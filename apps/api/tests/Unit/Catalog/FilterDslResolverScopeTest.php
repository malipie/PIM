<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Application\Filter\AttributeMetadataResolver;
use App\Catalog\Application\Filter\FilterDslResolver;
use App\Catalog\Application\Filter\FilterScopeResolver;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Channel\Contracts\ScopeEnumeratorInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * #2673 — value-context scope (`scope: {channel?, locale?}`) on the SQL
 * path: the effective value of a condition is COALESCE(best-matching
 * scope-specific `object_values` slot, global `attributes_indexed` slot).
 */
final class FilterDslResolverScopeTest extends TestCase
{
    private const string CHANNEL_ID = '0198c9a0-0000-7000-8000-0000000000aa';

    /** @var array<string, string> code => attribute uuid */
    private array $attributeIds = [];

    public function testNoScopeCompilesByteIdenticalSql(): void
    {
        $dsl = [
            'operator' => 'AND',
            'conditions' => [
                ['attr' => 'brand', 'op' => '=', 'value' => 'Festo'],
                ['attr' => 'weight', 'op' => '>', 'value' => 10],
            ],
        ];

        $withScopeWiring = $this->scopedResolver([
            'brand' => [AttributeType::Text, true, true],
            'weight' => [AttributeType::Number, false, false],
        ]);
        $plain = $this->plainResolver([
            'brand' => [AttributeType::Text, true, true],
            'weight' => [AttributeType::Number, false, false],
        ]);

        self::assertSame(
            $plain->toCountSql($dsl),
            $withScopeWiring->toCountSql($dsl),
            'DSL without scope must compile to byte-identical SQL (regression guard)',
        );
    }

    public function testChannelScopeAddsCoalescedSubselect(): void
    {
        $resolver = $this->scopedResolver(['brand' => [AttributeType::Text, false, true]]);
        $sql = $resolver->toCountSql([
            'scope' => ['channel' => 'shopify'],
            'attr' => 'brand',
            'op' => '=',
            'value' => 'Festo',
        ]);

        self::assertNotNull($sql);
        self::assertStringContainsString('COALESCE((SELECT', $sql);
        self::assertStringContainsString("ov.attribute_id = '".$this->attributeIds['brand']."'", $sql);
        self::assertStringContainsString("ov.channel_id = '".self::CHANNEL_ID."'", $sql);
        self::assertStringContainsString('ov.locale IS NULL', $sql);
        self::assertStringContainsString('ov.tenant_id = co.tenant_id', $sql);
        self::assertStringContainsString("NULLIF((co.attributes_indexed->'brand'->>'value'), '')", $sql);
        self::assertStringNotContainsString('ORDER BY', $sql);
    }

    public function testLocaleScopeAddsLocalePredicate(): void
    {
        $resolver = $this->scopedResolver(['description' => [AttributeType::Text, true, false]]);
        $sql = $resolver->toCountSql([
            'scope' => ['locale' => 'pl'],
            'attr' => 'description',
            'op' => 'contains',
            'value' => 'promo',
        ]);

        self::assertNotNull($sql);
        self::assertStringContainsString("ov.locale = 'pl'", $sql);
        self::assertStringContainsString('ov.channel_id IS NULL', $sql);
    }

    public function testBothDimensionsOrderBySpecificity(): void
    {
        $resolver = $this->scopedResolver(['description' => [AttributeType::Text, true, true]]);
        $sql = $resolver->toCountSql([
            'scope' => ['channel' => 'shopify', 'locale' => 'pl'],
            'attr' => 'description',
            'op' => 'IS NOT EMPTY',
        ]);

        self::assertNotNull($sql);
        self::assertStringContainsString("(ov.channel_id = '".self::CHANNEL_ID."' OR ov.channel_id IS NULL)", $sql);
        self::assertStringContainsString("(ov.locale = 'pl' OR ov.locale IS NULL)", $sql);
        self::assertStringContainsString('NOT (ov.channel_id IS NULL AND ov.locale IS NULL)', $sql);
        self::assertStringContainsString('ORDER BY (ov.channel_id IS NOT NULL) DESC, (ov.locale IS NOT NULL) DESC', $sql);
        self::assertStringContainsString('LIMIT 1', $sql);
    }

    public function testScopeTrimmedToAttributeCapabilities(): void
    {
        // Non-localizable + non-scopable attribute → pure legacy expression.
        $resolver = $this->scopedResolver(['weight' => [AttributeType::Number, false, false]]);
        $sql = $resolver->toCountSql([
            'scope' => ['channel' => 'shopify', 'locale' => 'pl'],
            'attr' => 'weight',
            'op' => '>',
            'value' => 5,
        ]);

        self::assertNotNull($sql);
        self::assertStringNotContainsString('object_values', $sql);
        self::assertStringContainsString("co.attributes_indexed->'weight'", $sql);
    }

    public function testColumnMapAndDotPathIgnoreScope(): void
    {
        $resolver = $this->scopedResolver(['description' => [AttributeType::Text, true, true]]);

        $skuSql = $resolver->toCountSql([
            'scope' => ['channel' => 'shopify'],
            'attr' => 'sku',
            'op' => '=',
            'value' => 'ABC-1',
        ]);
        self::assertNotNull($skuSql);
        self::assertStringNotContainsString('object_values', $skuSql);
        self::assertStringContainsString('co.code', $skuSql);

        $dotSql = $resolver->toCountSql([
            'scope' => ['channel' => 'shopify', 'locale' => 'en'],
            'attr' => 'description.pl',
            'op' => 'IS NOT EMPTY',
        ]);
        self::assertNotNull($dotSql);
        self::assertStringNotContainsString('object_values', $dotSql);
        self::assertStringContainsString("co.attributes_indexed->'description'->>'pl'", $dotSql);
    }

    public function testScopedMultiselectPrefersScopedSlot(): void
    {
        $resolver = $this->scopedResolver(['tags' => [AttributeType::Multiselect, false, true]]);
        $sql = $resolver->toCountSql([
            'scope' => ['channel' => 'shopify'],
            'attr' => 'tags',
            'op' => 'contains',
            'value' => 'sale',
        ]);

        self::assertNotNull($sql);
        self::assertStringContainsString("NULLIF((ov.value->'option_codes'), '[]'::jsonb)", $sql);
        self::assertStringContainsString("co.attributes_indexed->'tags'->'option_codes'", $sql);
        self::assertStringContainsString('@>', $sql);
    }

    public function testScopedIsEmptyReadsEffectiveValue(): void
    {
        $resolver = $this->scopedResolver(['brand' => [AttributeType::Text, false, true]]);
        $sql = $resolver->toCountSql([
            'scope' => ['channel' => 'shopify'],
            'attr' => 'brand',
            'op' => 'IS EMPTY',
        ]);

        self::assertNotNull($sql);
        self::assertStringContainsString('COALESCE((SELECT', $sql);
        self::assertStringEndsWith('IS NULL', trim($sql));
    }

    public function testUnknownScopeChannelYieldsNullSql(): void
    {
        // toCountSql swallows resolution errors (graceful degradation);
        // validate() is the loud path — covered in FilterScopeResolverTest.
        $resolver = $this->scopedResolver(['brand' => [AttributeType::Text, false, true]]);

        self::assertNull($resolver->toCountSql([
            'scope' => ['channel' => 'does-not-exist'],
            'attr' => 'brand',
            'op' => '=',
            'value' => 'x',
        ]));
    }

    public function testHasScopeDetection(): void
    {
        self::assertTrue(FilterDslResolver::hasScope(['scope' => ['channel' => 'shopify'], 'attr' => 'a', 'op' => '=']));
        self::assertTrue(FilterDslResolver::hasScope(['scope' => ['locale' => 'pl'], 'attr' => 'a', 'op' => '=']));
        self::assertFalse(FilterDslResolver::hasScope(['scope' => [], 'attr' => 'a', 'op' => '=']));
        self::assertFalse(FilterDslResolver::hasScope(['scope' => ['channel' => ''], 'attr' => 'a', 'op' => '=']));
        self::assertFalse(FilterDslResolver::hasScope(['attr' => 'a', 'op' => '=']));
    }

    public function testMeilisearchCompilerRejectsScope(): void
    {
        $resolver = $this->scopedResolver(['brand' => [AttributeType::Text, false, true]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/SQL prefilter/');
        $resolver->toMeilisearchFilter([
            'scope' => ['channel' => 'shopify'],
            'attr' => 'brand',
            'op' => '=',
            'value' => 'Festo',
        ]);
    }

    /**
     * Resolver with metadata + scope wiring. `$attrs` maps
     * code => [type, localizable, scopable].
     *
     * @param array<string, array{0: AttributeType, 1: bool, 2: bool}> $attrs
     */
    private function scopedResolver(array $attrs): FilterDslResolver
    {
        $tenantContext = new TenantContext();
        $tenantContext->set(new Tenant('unit', 'Unit Tenant'));

        $metadata = new AttributeMetadataResolver($this->attributeRepository($attrs), $tenantContext);

        $scopes = $this->createStub(ScopeEnumeratorInterface::class);
        $scopes->method('channelIdsByCode')->willReturn(['shopify' => self::CHANNEL_ID]);
        $scopes->method('localeShortCodes')->willReturn(['pl', 'en']);

        return new FilterDslResolver($metadata, new FilterScopeResolver($scopes, $tenantContext));
    }

    /**
     * @param array<string, array{0: AttributeType, 1: bool, 2: bool}> $attrs
     */
    private function plainResolver(array $attrs): FilterDslResolver
    {
        $tenantContext = new TenantContext();
        $tenantContext->set(new Tenant('unit', 'Unit Tenant'));

        return new FilterDslResolver(new AttributeMetadataResolver($this->attributeRepository($attrs), $tenantContext));
    }

    /**
     * @param array<string, array{0: AttributeType, 1: bool, 2: bool}> $attrs
     */
    private function attributeRepository(array $attrs): AttributeRepositoryInterface
    {
        $ids = &$this->attributeIds;
        $repository = $this->createStub(AttributeRepositoryInterface::class);
        $repository->method('findByCode')->willReturnCallback(
            static function (string $code) use ($attrs, &$ids): ?Attribute {
                if (!isset($attrs[$code])) {
                    return null;
                }
                [$type, $localizable, $scopable] = $attrs[$code];
                $attribute = new Attribute($code, ['en' => $code], $type, Uuid::v7());
                $attribute->changeLocalizable($localizable);
                $attribute->changeScopable($scopable);
                $ids[$code] = $attribute->getId()->toRfc4122();

                return $attribute;
            },
        );

        return $repository;
    }
}
