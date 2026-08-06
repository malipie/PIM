<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Application\Filter\AttributeMetadataResolver;
use App\Catalog\Application\Filter\FilterDslResolver;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * VIEW-09 (#535) — FilterDslResolver covers:
 *   - validation rejects unsupported operators / malformed DSL.
 *   - validation accepts the 5 built-in preset DSLs verbatim.
 *   - toCountSql compiles flat + grouped conditions to safe SQL.
 *   - identifier safety (no SQL injection via attribute name).
 */
final class FilterDslResolverTest extends TestCase
{
    private FilterDslResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new FilterDslResolver();
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function builtInPresetDslProvider(): iterable
    {
        yield 'inconsistent-translations' => [[
            'operator' => 'AND',
            'conditions' => [
                ['attr' => 'description.pl', 'op' => 'IS NOT EMPTY'],
                ['attr' => 'description.en', 'op' => 'IS EMPTY'],
            ],
        ]];
        yield 'missing-images' => [['attr' => 'main_image', 'op' => 'IS EMPTY']];
        yield 'weak-seo' => [[
            'operator' => 'AND',
            'conditions' => [
                ['attr' => 'description', 'op' => 'IS NOT EMPTY'],
                ['attr' => 'meta_description', 'op' => 'IS EMPTY'],
            ],
        ]];
        yield 'red-low-completeness' => [['attr' => 'completeness_pct', 'op' => '<', 'value' => 50]];
        yield 'no-category' => [['attr' => 'category', 'op' => 'IS EMPTY']];
    }

    /**
     * @param array<string, mixed> $dsl
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('builtInPresetDslProvider')]
    public function testValidateAcceptsBuiltInPresets(array $dsl): void
    {
        $this->resolver->validate($dsl);
        $sql = $this->resolver->toCountSql($dsl);

        self::assertNotNull($sql, 'built-in DSL must compile to SQL fragment');
        self::assertIsString($sql);
    }

    public function testValidateRejectsUnsupportedOperator(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessageMatches('/Operator "REGEX MATCH" not supported/');

        $this->resolver->validate(['attr' => 'brand', 'op' => 'REGEX MATCH', 'value' => '^F.*$']);
    }

    public function testValidateRejectsMalformedGroup(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->resolver->validate(['operator' => 'XOR', 'conditions' => []]);
    }

    public function testValidateRejectsConditionMissingValue(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessageMatches('/requires a value/');

        $this->resolver->validate(['attr' => 'brand', 'op' => '=']);
    }

    public function testValidateRejectsInWithoutArrayValue(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessageMatches('/requires an array value/');

        $this->resolver->validate(['attr' => 'brand', 'op' => 'IN', 'value' => 'Festo']);
    }

    public function testValidateRejectsUnsafeIdentifier(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->resolver->validate(['attr' => "brand'; DROP TABLE--", 'op' => '=', 'value' => 'x']);
    }

    public function testValidateRejectsDeeplyNestedGroup(): void
    {
        // 5 nested groups → depth 4 throws (max depth = 3 per PRD §13.2).
        $deeplyNested = [
            'operator' => 'AND',
            'conditions' => [[
                'operator' => 'OR',
                'conditions' => [[
                    'operator' => 'AND',
                    'conditions' => [[
                        'operator' => 'OR',
                        'conditions' => [[
                            'operator' => 'AND',
                            'conditions' => [['attr' => 'brand', 'op' => '=', 'value' => 'Festo']],
                        ]],
                    ]],
                ]],
            ]],
        ];

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessageMatches('/nesting too deep/');
        $this->resolver->validate($deeplyNested);
    }

    public function testToCountSqlEmitsNullCheckForIsEmpty(): void
    {
        $sql = $this->resolver->toCountSql(['attr' => 'main_image', 'op' => 'IS EMPTY']);

        self::assertNotNull($sql);
        self::assertStringContainsString("attributes_indexed->>'main_image'", $sql);
        self::assertStringContainsString('IS NULL', $sql);
    }

    /**
     * #2237 — the Meili document stores the natural key as `code`, so the
     * `sku` DSL alias must compile to a `code` filter (mirroring COLUMN_MAP
     * `sku => co.code` on the SQL path). Before this, `sku = "…"` hit a
     * non-existent Meili field and the agent's grounding search degraded,
     * misread as a backend outage.
     */
    public function testToMeilisearchFilterMapsSkuAliasToCode(): void
    {
        $filter = $this->resolver->toMeilisearchFilter(['attr' => 'sku', 'op' => '=', 'value' => 'DEMO-100']);

        self::assertSame('code = "DEMO-100"', $filter);
        self::assertStringNotContainsString('sku', $filter);
    }

    public function testToCountSqlEmitsLocaleScopedJsonPath(): void
    {
        $sql = $this->resolver->toCountSql([
            'operator' => 'AND',
            'conditions' => [
                ['attr' => 'description.pl', 'op' => 'IS NOT EMPTY'],
                ['attr' => 'description.en', 'op' => 'IS EMPTY'],
            ],
        ]);

        self::assertNotNull($sql);
        self::assertStringContainsString("'description'->>'pl'", $sql);
        self::assertStringContainsString("'description'->>'en'", $sql);
        self::assertStringContainsString(' AND ', $sql);
    }

    public function testToCountSqlEmitsColumnReferenceForReservedNames(): void
    {
        $sql = $this->resolver->toCountSql(['attr' => 'completeness_pct', 'op' => '<', 'value' => 50]);

        self::assertNotNull($sql);
        self::assertStringContainsString('co.completeness_pct < 50', $sql);
    }

    /**
     * #2526 — the editorial state is a column (`co.status`), NOT a JSONB
     * attribute, so scoping exports/feeds/lists by status resolves to a
     * direct column comparison (not `attributes_indexed->>'status'`).
     */
    public function testToCountSqlEmitsStatusColumnReference(): void
    {
        $sql = $this->resolver->toCountSql(['attr' => 'status', 'op' => '=', 'value' => 'published']);

        self::assertNotNull($sql);
        self::assertStringContainsString("co.status = 'published'", $sql);
        self::assertStringNotContainsString('attributes_indexed', $sql);
    }

    public function testToCountSqlHandlesStatusInList(): void
    {
        $sql = $this->resolver->toCountSql([
            'attr' => 'status',
            'op' => 'IN',
            'value' => ['draft', 'review'],
        ]);

        self::assertNotNull($sql);
        self::assertStringContainsString("co.status IN ('draft', 'review')", $sql);
    }

    /**
     * #2526 — on the Meili path the editorial state resolves to the indexed
     * root facet `status` (CatalogObjectIndexer), not a JSONB dot-path.
     */
    public function testToMeilisearchFilterMapsStatusToRootFacet(): void
    {
        $filter = $this->resolver->toMeilisearchFilter([
            'attr' => 'status',
            'op' => '=',
            'value' => 'published',
        ]);

        self::assertStringContainsString('status = "published"', $filter);
        self::assertStringNotContainsString('attributes_indexed', $filter);
    }

    public function testToCountSqlEscapesStringLiteralsSafely(): void
    {
        $sql = $this->resolver->toCountSql(['attr' => 'brand', 'op' => '=', 'value' => "O'Reilly"]);

        self::assertNotNull($sql);
        self::assertStringContainsString("'O''Reilly'", $sql);
        self::assertStringNotContainsString("'O'Reilly'", $sql); // not bare single-quote
    }

    public function testToCountSqlNeutralisesOrInjectionPayloadAsSingleLiteral(): void
    {
        // AUD-031 / W2-3 (C-2) — the canonical SQLi probe must compile to ONE
        // escaped string literal (every inner quote doubled), so Postgres
        // (standard_conforming_strings=on, enforced by the connection-init
        // middleware) reads it as a single value, never a closed string + OR.
        $sql = $this->resolver->toCountSql(['attr' => 'brand', 'op' => '=', 'value' => "x' OR '1'='1"]);

        self::assertNotNull($sql);
        self::assertStringContainsString("= 'x'' OR ''1''=''1'", $sql);
        // No un-doubled quote that could terminate the literal early.
        self::assertStringNotContainsString("'x' OR", $sql);
    }

    public function testToCountSqlReturnsNullForCompilationFailure(): void
    {
        // Suppress validation: pass directly to compile via toCountSql.
        $sql = $this->resolver->toCountSql(['attr' => "brand'; DROP", 'op' => '=', 'value' => 'x']);
        self::assertNull($sql, 'unsafe identifier must return null SQL, not throw');
    }

    public function testToCountSqlHandlesInList(): void
    {
        $sql = $this->resolver->toCountSql(['attr' => 'brand', 'op' => 'IN', 'value' => ['Festo', 'Bosch']]);

        self::assertNotNull($sql);
        self::assertStringContainsString("IN ('Festo', 'Bosch')", $sql);
    }

    public function testToCountSqlHandlesNotInList(): void
    {
        $sql = $this->resolver->toCountSql(['attr' => 'brand', 'op' => 'NOT IN', 'value' => ['Bosch']]);

        self::assertNotNull($sql);
        self::assertStringContainsString("NOT IN ('Bosch')", $sql);
    }

    // ── #2627 — type-aware envelope SQL ─────────────────────────────────
    //
    // `attributes_indexed` slots are envelopes ({value}, {amount, currency},
    // {option_code}, {option_codes}); the SQL path must descend into the
    // typed key and cast numerics, otherwise numeric comparisons crash
    // Postgres (`text < integer` — the bug that wedged every PDF catalog
    // run) and text comparisons silently never match.

    public function testTypedPriceComparisonDescendsToAmountAndCastsNumeric(): void
    {
        $sql = $this->typedResolver(['price' => AttributeType::Price])
            ->toCountSql(['attr' => 'price', 'op' => '<', 'value' => 200]);

        self::assertNotNull($sql);
        self::assertStringContainsString("(NULLIF((co.attributes_indexed->'price'->>'amount'), ''))::numeric < 200", $sql);
    }

    public function testTypedPriceBetweenCastsNumeric(): void
    {
        $sql = $this->typedResolver(['price' => AttributeType::Price])
            ->toCountSql(['attr' => 'price', 'op' => 'BETWEEN', 'value' => [100, 200]]);

        self::assertNotNull($sql);
        self::assertStringContainsString("'price'->>'amount'", $sql);
        self::assertStringContainsString('::numeric BETWEEN 100 AND 200', $sql);
    }

    public function testTypedNumberComparisonDescendsToValue(): void
    {
        $sql = $this->typedResolver(['refresh_rate' => AttributeType::Number])
            ->toCountSql(['attr' => 'refresh_rate', 'op' => '>', 'value' => 120]);

        self::assertNotNull($sql);
        self::assertStringContainsString("(NULLIF((co.attributes_indexed->'refresh_rate'->>'value'), ''))::numeric > 120", $sql);
    }

    public function testTypedMetricComparisonDescendsToValue(): void
    {
        $sql = $this->typedResolver(['weight' => AttributeType::Metric])
            ->toCountSql(['attr' => 'weight', 'op' => '≤', 'value' => 2.5]);

        self::assertNotNull($sql);
        self::assertStringContainsString("'weight'->>'value'", $sql);
        self::assertStringContainsString('::numeric <= 2.5', $sql);
    }

    public function testTypedNumericEqualityUsesUnquotedLiteral(): void
    {
        $sql = $this->typedResolver(['price' => AttributeType::Price])
            ->toCountSql(['attr' => 'price', 'op' => '=', 'value' => '199.99']);

        self::assertNotNull($sql);
        self::assertStringContainsString('::numeric = 199.99', $sql);
        self::assertStringNotContainsString("'199.99'", $sql);
    }

    public function testTypedBooleanCastsAndComparesTrue(): void
    {
        $sql = $this->typedResolver(['in_stock' => AttributeType::Boolean])
            ->toCountSql(['attr' => 'in_stock', 'op' => '= TRUE']);

        self::assertNotNull($sql);
        self::assertStringContainsString("(NULLIF((co.attributes_indexed->'in_stock'->>'value'), ''))::boolean = true", $sql);
    }

    public function testTypedSelectDescendsToOptionCode(): void
    {
        $sql = $this->typedResolver(['color' => AttributeType::Select])
            ->toCountSql(['attr' => 'color', 'op' => '=', 'value' => 'red']);

        self::assertNotNull($sql);
        self::assertStringContainsString("NULLIF((co.attributes_indexed->'color'->>'option_code'), '') = 'red'", $sql);
    }

    public function testTypedTextDescendsToValue(): void
    {
        $sql = $this->typedResolver(['brand' => AttributeType::Text])
            ->toCountSql(['attr' => 'brand', 'op' => '=', 'value' => 'Voltix']);

        self::assertNotNull($sql);
        self::assertStringContainsString("NULLIF((co.attributes_indexed->'brand'->>'value'), '') = 'Voltix'", $sql);
    }

    public function testTypedDateAfterComparesIsoText(): void
    {
        $sql = $this->typedResolver(['release_date' => AttributeType::Date])
            ->toCountSql(['attr' => 'release_date', 'op' => 'AFTER', 'value' => '2026-01-01']);

        self::assertNotNull($sql);
        self::assertStringContainsString("NULLIF((co.attributes_indexed->'release_date'->>'value'), '') > '2026-01-01'", $sql);
    }

    public function testTypedMultiselectContainsUsesJsonbContainment(): void
    {
        $sql = $this->typedResolver(['tags' => AttributeType::Multiselect])
            ->toCountSql(['attr' => 'tags', 'op' => 'CONTAINS', 'value' => 'new']);

        self::assertNotNull($sql);
        self::assertStringContainsString("co.attributes_indexed->'tags'->'option_codes' @> '[\"new\"]'::jsonb", $sql);
        self::assertStringNotContainsString(' ? ', $sql, 'the ? operator would be misread as a PDO placeholder');
    }

    public function testTypedMultiselectNotContainsCountsMissingSlotAsNotContaining(): void
    {
        $sql = $this->typedResolver(['tags' => AttributeType::Multiselect])
            ->toCountSql(['attr' => 'tags', 'op' => 'NOT CONTAINS', 'value' => 'sale']);

        self::assertNotNull($sql);
        self::assertStringContainsString('COALESCE(NOT (', $sql);
        self::assertStringContainsString('@> \'["sale"]\'::jsonb), true)', $sql);
    }

    public function testTypedMultiselectIsEmptyTreatsEmptyListAsEmpty(): void
    {
        $sql = $this->typedResolver(['tags' => AttributeType::Multiselect])
            ->toCountSql(['attr' => 'tags', 'op' => 'IS EMPTY']);

        self::assertNotNull($sql);
        self::assertStringContainsString("NULLIF((co.attributes_indexed->'tags'->>'option_codes'), '[]') IS NULL", $sql);
    }

    public function testTypedReservedColumnKeepsColumnReference(): void
    {
        // completeness_pct resolves to a physical column even with metadata
        // wired — the reserved COLUMN_MAP must win over envelope descent.
        $sql = $this->typedResolver([])
            ->toCountSql(['attr' => 'completeness_pct', 'op' => '<', 'value' => 50]);

        self::assertNotNull($sql);
        self::assertStringContainsString('co.completeness_pct < 50', $sql);
        self::assertStringNotContainsString('::numeric', $sql);
    }

    public function testWithoutMetadataResolverLegacyExpressionIsKept(): void
    {
        // Unit-test / degraded mode: no metadata → the pre-#2627 shape, so
        // behavior degrades predictably instead of guessing envelope keys.
        $sql = $this->resolver->toCountSql(['attr' => 'price', 'op' => '<', 'value' => 200]);

        self::assertNotNull($sql);
        self::assertStringContainsString("NULLIF((co.attributes_indexed->>'price'), '') < 200", $sql);
    }

    /**
     * Build a resolver with a real {@see AttributeMetadataResolver} backed by
     * a stubbed repository mapping code => AttributeType.
     *
     * @param array<string, AttributeType> $types
     */
    private function typedResolver(array $types): FilterDslResolver
    {
        $repository = $this->createStub(AttributeRepositoryInterface::class);
        $repository->method('findByCode')->willReturnCallback(
            static function (string $code) use ($types): ?Attribute {
                if (!isset($types[$code])) {
                    return null;
                }

                return new Attribute($code, ['en' => $code], $types[$code]);
            },
        );
        $tenantContext = new TenantContext();
        $tenantContext->set(new Tenant('unit', 'Unit Tenant'));

        return new FilterDslResolver(new AttributeMetadataResolver($repository, $tenantContext));
    }
}
