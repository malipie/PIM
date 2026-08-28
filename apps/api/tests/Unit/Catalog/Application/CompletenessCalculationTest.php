<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\AttributesIndexedRebuilder;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Service\EffectiveAttributeGroupResolver;
use App\Channel\Contracts\LocaleFallbackResolverInterface;
use App\Channel\Contracts\ScopeEnumeratorInterface;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Property-based-style coverage of the completeness calculation in
 * {@see AttributesIndexedRebuilder::rebuild} (audit / 0.11.6, per-scope #1152).
 *
 * The listener-level integration test
 * ({@see \App\Tests\Integration\Catalog\AttributesIndexedSyncListenerTest})
 * verifies the wiring; this unit test enumerates the algorithm's
 * properties directly so regressions in the math (rounding, denominator
 * handling, missing-attribute counting, per-scope resolution) surface
 * without a DB round-trip.
 */
final class CompletenessCalculationTest extends TestCase
{
    /**
     * @return iterable<string, array{
     *     required: list<mixed>,
     *     present: list<string>,
     *     expectedPct: int,
     * }>
     */
    public static function completenessCases(): iterable
    {
        yield 'empty rules + zero values → 100' => [
            'required' => [],
            'present' => [],
            'expectedPct' => 100,
        ];
        yield 'empty rules + many values → 100' => [
            'required' => [],
            'present' => ['name', 'brand', 'description', 'sku'],
            'expectedPct' => 100,
        ];
        yield 'all required present → 100' => [
            'required' => ['name', 'brand'],
            'present' => ['name', 'brand'],
            'expectedPct' => 100,
        ];
        yield 'none required present → 0' => [
            'required' => ['name', 'brand'],
            'present' => [],
            'expectedPct' => 0,
        ];
        yield 'half required present → 50' => [
            'required' => ['name', 'brand'],
            'present' => ['name'],
            'expectedPct' => 50,
        ];
        yield 'one of three required → 33 (rounded down)' => [
            'required' => ['name', 'brand', 'description'],
            'present' => ['name'],
            'expectedPct' => 33,
        ];
        yield 'two of three required → 67 (rounded up)' => [
            'required' => ['name', 'brand', 'description'],
            'present' => ['name', 'brand'],
            'expectedPct' => 67,
        ];
        yield 'one of six required → 17 (banker rounding)' => [
            'required' => ['a', 'b', 'c', 'd', 'e', 'f'],
            'present' => ['a'],
            'expectedPct' => 17,
        ];
        yield 'extra non-required values do not raise pct' => [
            'required' => ['name'],
            'present' => ['name', 'brand', 'description'],
            'expectedPct' => 100,
        ];
        yield 'extra non-required values when nothing required is present → 0' => [
            'required' => ['name'],
            'present' => ['brand', 'description'],
            'expectedPct' => 0,
        ];
        yield 'non-string entries in required are ignored' => [
            'required' => ['name', 42, null, true, 'brand'],
            'present' => ['name', 'brand'],
            // Non-string entries are dropped from the denominator (codeList
            // keeps only strings), so 2 real codes both present → 100.
            'expectedPct' => 100,
        ];
    }

    /**
     * @param list<mixed>  $required
     * @param list<string> $present
     */
    #[Test]
    #[DataProvider('completenessCases')]
    public function rebuildComputesCompletenessFromRequiredListAndPresentValues(
        array $required,
        array $present,
        int $expectedPct,
    ): void {
        $rebuilder = $this->newRebuilder();

        $tenant = self::tenantStub();
        $objectType = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $objectType->assignTenant($tenant);
        $objectType->updateCompletenessRules(['required' => $required]);

        $object = new CatalogObject($objectType, 'SKU-PROP-1');
        $values = self::buildGlobalValues($object, $tenant, $present);

        $rebuilder->rebuild($object, $values);

        $completeness = $object->getCompleteness();
        self::assertArrayHasKey('global', $completeness);
        self::assertGreaterThanOrEqual(0, $completeness['global']);
        self::assertLessThanOrEqual(100, $completeness['global']);
        self::assertSame($expectedPct, $completeness['global']);
        // No tenant scopes enumerated → only the global reading is recorded.
        self::assertSame(['global' => $expectedPct], $completeness);
    }

    #[Test]
    public function emptyValuesListWithNoRulesYieldsHundredAndEmptyAttributesIndexed(): void
    {
        $rebuilder = $this->newRebuilder();

        $tenant = self::tenantStub();
        $objectType = new ObjectType('asset', ObjectKind::Asset, ['en' => 'Asset']);
        $objectType->assignTenant($tenant);

        $object = new CatalogObject($objectType, 'IMG-PROP-1');
        $rebuilder->rebuild($object, []);

        self::assertSame(['global' => 100], $object->getCompleteness());
        self::assertSame([], $object->getAttributesIndexed());
    }

    #[Test]
    public function rebuildComputesPerLocaleAndPerChannelCompleteness(): void
    {
        // #1152 — `desc` is localizable + scopable with NO global value, only
        // a pl-locale row and an allegro-channel row. `name` is plain global.
        // required_per_channel adds `extra` (unfilled) for allegro.
        $allegroId = Uuid::v7();
        $scopes = $this->createStub(ScopeEnumeratorInterface::class);
        $scopes->method('localeShortCodes')->willReturn(['pl', 'en']);
        $scopes->method('channelIdsByCode')->willReturn(['allegro' => $allegroId->toRfc4122()]);
        $fallback = $this->createStub(LocaleFallbackResolverInterface::class);
        // en falls back to the primary pl.
        $fallback->method('resolve')->willReturnCallback(
            static fn (string $code): array => 'en' === $code ? ['en', 'pl'] : [$code],
        );

        $rebuilder = $this->newRebuilder($scopes, $fallback);

        $tenant = new Tenant('demo-scope', 'Demo'); // primaryLocale defaults to 'pl'
        $objectType = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $objectType->assignTenant($tenant);
        $objectType->updateCompletenessRules([
            'required' => ['name', 'desc'],
            'required_per_channel' => ['allegro' => ['extra']],
        ]);

        $object = new CatalogObject($objectType, 'SKU-SCOPE-1');
        $object->assignTenant($tenant); // production: stamped by TenantAssignmentListener on persist

        $name = self::attr('name', $tenant);
        $desc = self::attr('desc', $tenant);
        $desc->changeLocalizable(true);
        $desc->changeScopable(true);

        $values = [
            new ObjectValue($object, $name, ['value' => 'Name']),                    // global
            new ObjectValue($object, $desc, ['value' => 'Opis PL'], locale: 'pl'),   // pl-locale, no global
            new ObjectValue($object, $desc, ['value' => 'Opis Allegro'], channelId: $allegroId), // channel
        ];

        $rebuilder->rebuild($object, $values);
        $completeness = $object->getCompleteness();

        // global: name present globally, desc has no global row → 1/2.
        self::assertSame(50, $completeness['global']);
        // per_locale['en']: name (non-loc → global) + desc (en→pl fallback hits the pl row) → 2/2.
        self::assertSame(['en' => 100], $completeness['per_locale']);
        // per_channel['allegro']: effective required = name, desc, extra.
        // name (global) + desc (allegro row) present, extra absent → 2/3 = 67.
        self::assertSame(['allegro' => 67], $completeness['per_channel']);
    }

    private function newRebuilder(
        ?ScopeEnumeratorInterface $scopes = null,
        ?LocaleFallbackResolverInterface $fallback = null,
    ): AttributesIndexedRebuilder {
        // Resolver stub returns empty effective groups — the rebuilder's
        // MOD-09 effective-model filter is bypassed (empty effective set
        // falls back to the legacy `required` count).
        $resolver = $this->createStub(EffectiveAttributeGroupResolver::class);
        $resolver->method('resolve')->willReturn([]);
        $resolver->method('loadGroupAttributes')->willReturn([]);

        if (null === $scopes) {
            $scopes = $this->createStub(ScopeEnumeratorInterface::class);
            $scopes->method('localeShortCodes')->willReturn([]);
            $scopes->method('channelIdsByCode')->willReturn([]);
        }
        $fallback ??= $this->createStub(LocaleFallbackResolverInterface::class);

        return new AttributesIndexedRebuilder(
            $this->createStub(EntityManagerInterface::class),
            $resolver,
            $scopes,
            $fallback,
        );
    }

    /**
     * @param list<string> $presentCodes
     *
     * @return list<ObjectValue>
     */
    private static function buildGlobalValues(CatalogObject $object, Tenant $tenant, array $presentCodes): array
    {
        $values = [];
        foreach ($presentCodes as $code) {
            $values[] = new ObjectValue($object, self::attr($code, $tenant), ['value' => 'sample']);
        }

        return $values;
    }

    private static function attr(string $code, Tenant $tenant): Attribute
    {
        $attribute = new Attribute($code, ['en' => ucfirst($code)], AttributeType::Text);
        $attribute->assignTenant($tenant);

        return $attribute;
    }

    private static function tenantStub(): Tenant
    {
        return new Tenant('demo-test-'.bin2hex(random_bytes(2)), 'Demo');
    }
}
