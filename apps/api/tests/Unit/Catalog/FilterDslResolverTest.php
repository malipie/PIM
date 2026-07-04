<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Application\Filter\FilterDslResolver;
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
}
