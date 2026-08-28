<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Application\Filter\FilterDslResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * VIEW-10 (#538) — parametrised coverage for the 25 operators × 8 types
 * matrix in PRD §5.5. Each (type, operator) pair compiles to both the
 * SQL count fragment and the Meilisearch filter expression so any
 * future regression in the resolver fires loudly.
 */
final class FilterDslResolverOperatorMatrixTest extends TestCase
{
    private FilterDslResolver $resolver;

    protected function setUp(): void
    {
        // No AttributeMetadataResolver — keeps the unit test database-free.
        // validateOperatorForType becomes a no-op; the resolver still
        // enforces the global op-list via OPERATORS_BY_TYPE in validate().
        $this->resolver = new FilterDslResolver();
    }

    /**
     * @return iterable<string, array{0: array{type: string, op: string, value?: mixed}}>
     */
    public static function operatorMatrixProvider(): iterable
    {
        $rows = [
            ['type' => 'text', 'op' => '=', 'value' => 'Festo'],
            ['type' => 'text', 'op' => '!=', 'value' => 'Festo'],
            ['type' => 'text', 'op' => 'IS EMPTY'],
            ['type' => 'text', 'op' => 'IS NOT EMPTY'],
            ['type' => 'text', 'op' => 'starts with', 'value' => 'Fes'],
            ['type' => 'text', 'op' => 'ends with', 'value' => 'sto'],
            ['type' => 'text', 'op' => 'contains', 'value' => 'est'],
            ['type' => 'text', 'op' => 'not contains', 'value' => 'foo'],
            ['type' => 'number', 'op' => '=', 'value' => 50],
            ['type' => 'number', 'op' => '!=', 'value' => 50],
            ['type' => 'number', 'op' => '<', 'value' => 50],
            ['type' => 'number', 'op' => '>', 'value' => 50],
            ['type' => 'number', 'op' => '<=', 'value' => 50],
            ['type' => 'number', 'op' => '>=', 'value' => 50],
            ['type' => 'number', 'op' => 'between', 'value' => [10, 90]],
            ['type' => 'number', 'op' => 'IS EMPTY'],
            ['type' => 'number', 'op' => 'IS NOT EMPTY'],
            ['type' => 'date', 'op' => '=', 'value' => '2026-05-14'],
            ['type' => 'date', 'op' => 'after', 'value' => '2026-01-01'],
            ['type' => 'date', 'op' => 'before', 'value' => '2026-12-31'],
            ['type' => 'date', 'op' => 'between', 'value' => ['2026-01-01', '2026-12-31']],
            ['type' => 'date', 'op' => 'IS EMPTY'],
            ['type' => 'date', 'op' => 'IS NOT EMPTY'],
            ['type' => 'select', 'op' => 'IN', 'value' => ['A', 'B']],
            ['type' => 'select', 'op' => 'NOT IN', 'value' => ['A']],
            ['type' => 'multiselect', 'op' => 'contains', 'value' => 'tag1'],
            ['type' => 'multiselect', 'op' => 'not contains', 'value' => 'tag2'],
            ['type' => 'boolean', 'op' => '= TRUE'],
            ['type' => 'boolean', 'op' => '= FALSE'],
            ['type' => 'relation', 'op' => 'IN', 'value' => ['x', 'y']],
            ['type' => 'asset', 'op' => 'IS EMPTY'],
            ['type' => 'asset', 'op' => 'IS NOT EMPTY'],
        ];

        foreach ($rows as $case) {
            $key = $case['type'].' '.$case['op'];
            yield $key => [$case];
        }
    }

    /**
     * @param array{type: string, op: string, value?: mixed} $case
     */
    #[DataProvider('operatorMatrixProvider')]
    public function testOperatorCompilesToSqlAndMeilisearch(array $case): void
    {
        // attr code arbitrary — resolver is type-agnostic without
        // AttributeMetadataResolver wired.
        $cond = ['attr' => 'brand', 'op' => $case['op']];
        if (\array_key_exists('value', $case)) {
            $cond['value'] = $case['value'];
        }

        // validate() must accept the canonical form.
        $this->resolver->validate($cond);

        // toCountSql produces a non-null SQL fragment.
        $sql = $this->resolver->toCountSql($cond);
        self::assertNotNull($sql, 'SQL compilation must succeed for '.$case['op']);

        // toMeilisearchFilter produces a non-empty expression.
        $meili = $this->resolver->toMeilisearchFilter($cond);
        self::assertNotEmpty($meili, 'Meili compilation must succeed for '.$case['op']);
    }

    /**
     * #2673 — every operator must also compile on the SQL path with an
     * active value-context scope (channel + locale, scopable+localizable
     * attribute → full COALESCE subselect variant).
     *
     * @param array{type: string, op: string, value?: mixed} $case
     */
    #[DataProvider('operatorMatrixProvider')]
    public function testOperatorCompilesToSqlWithScope(array $case): void
    {
        $resolver = $this->scopedResolver($case['type']);

        $cond = [
            'scope' => ['channel' => 'shopify', 'locale' => 'pl'],
            'attr' => 'brand',
            'op' => $case['op'],
        ];
        if (\array_key_exists('value', $case)) {
            $cond['value'] = $case['value'];
        }

        $sql = $resolver->toCountSql($cond);
        self::assertNotNull($sql, 'scoped SQL compilation must succeed for '.$case['type'].' '.$case['op']);
        self::assertStringContainsString('object_values', $sql);
    }

    private function scopedResolver(string $type): FilterDslResolver
    {
        $repository = $this->createStub(\App\Catalog\Domain\Repository\AttributeRepositoryInterface::class);
        $repository->method('findByCode')->willReturnCallback(
            static function (string $code) use ($type): \App\Catalog\Domain\Entity\Attribute {
                $attribute = new \App\Catalog\Domain\Entity\Attribute(
                    $code,
                    ['en' => $code],
                    \App\Catalog\Contracts\AttributeType::from($type),
                );
                $attribute->changeLocalizable(true);
                $attribute->changeScopable(true);

                return $attribute;
            },
        );
        $tenantContext = new \App\Shared\Application\TenantContext();
        $tenantContext->set(new \App\Shared\Domain\Tenant('unit', 'Unit Tenant'));

        $scopes = $this->createStub(\App\Channel\Contracts\ScopeEnumeratorInterface::class);
        $scopes->method('channelIdsByCode')->willReturn(['shopify' => '0198c9a0-0000-7000-8000-0000000000aa']);
        $scopes->method('localeShortCodes')->willReturn(['pl', 'en']);

        return new FilterDslResolver(
            new \App\Catalog\Application\Filter\AttributeMetadataResolver($repository, $tenantContext),
            new \App\Catalog\Application\Filter\FilterScopeResolver($scopes, $tenantContext),
        );
    }

    public function testOperatorsByTypeMatrixCovers8DomainTypes(): void
    {
        $expected = ['text', 'number', 'date', 'select', 'multiselect', 'boolean', 'relation', 'asset'];
        foreach ($expected as $type) {
            self::assertArrayHasKey($type, FilterDslResolver::OPERATORS_BY_TYPE, "type $type missing");
            self::assertNotEmpty(FilterDslResolver::OPERATORS_BY_TYPE[$type], "type $type has empty op list");
        }
    }

    public function testNormaliseOperatorHandlesAliases(): void
    {
        self::assertSame('!=', FilterDslResolver::normaliseOperator('≠'));
        self::assertSame('<=', FilterDslResolver::normaliseOperator('≤'));
        self::assertSame('>=', FilterDslResolver::normaliseOperator('≥'));
        self::assertSame('starts with', FilterDslResolver::normaliseOperator('STARTS WITH'));
        self::assertSame('starts with', FilterDslResolver::normaliseOperator('STARTS_WITH'));
        self::assertSame('= TRUE', FilterDslResolver::normaliseOperator('=TRUE'));
        self::assertSame('between', FilterDslResolver::normaliseOperator('BETWEEN'));
    }

    public function testMeiliFilterCompilesGroup(): void
    {
        $dsl = [
            'operator' => 'AND',
            'conditions' => [
                ['attr' => 'brand', 'op' => '=', 'value' => 'Festo'],
                ['attr' => 'completeness_pct', 'op' => '<', 'value' => 50],
            ],
        ];

        $meili = $this->resolver->toMeilisearchFilter($dsl);
        self::assertStringContainsString('brand = "Festo"', $meili);
        self::assertStringContainsString('completeness_pct < 50', $meili);
        self::assertStringContainsString(' AND ', $meili);
    }

    public function testMeiliFilterCompilesLocaleScopedAttribute(): void
    {
        $dsl = ['attr' => 'description.pl', 'op' => 'IS NOT EMPTY'];
        $meili = $this->resolver->toMeilisearchFilter($dsl);

        self::assertStringContainsString('description.pl EXISTS', $meili);
    }
}
