<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Application\Builder;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectCategory;
use App\Catalog\Domain\Entity\ObjectRelation;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectCategoryRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectRelationRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectValueRepositoryInterface;
use App\Channel\Contracts\ChannelResolverInterface;
use App\Export\Application\Builder\ColumnResolver;
use App\Export\Application\Builder\ExportBuilder;
use App\Export\Application\Builder\UnresolvedExportChannelException;
use App\Export\Application\Builder\ValueSerializer;
use App\Export\Domain\Entity\ExportSession;
use App\Export\Domain\Enum\ExportFormat;
use App\Export\Domain\Enum\ExportSource;
use App\Export\Domain\Enum\ExportTargetScope;
use App\Shared\Domain\Tenant;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * EXP-03 (#582) — ExportBuilder contract tests.
 *
 * Exercises the full per-row rendering pipeline with mocked repositories
 * and real ColumnResolver + ValueSerializer. Covers PRD §8 contracts
 * (variants flat, multi-locale columns, blank cells, pipe-multivalue,
 * stale-attribute degradation).
 */
final class ExportBuilderTest extends TestCase
{
    /**
     * #1632 — the builder now batch-loads per page keyed by object UUID, but the
     * fixtures below are keyed by spl_object_id($product). This registry bridges
     * the two: id (RFC4122) => product, so the batch stubs can resolve a
     * requested id back to the object and read its spl_object_id-keyed fixture.
     *
     * @var array<string, CatalogObject>
     */
    private array $objectsById = [];

    #[Test]
    public function buildThrowsWhenSessionHasNoTenant(): void
    {
        $builder = $this->newBuilder([], []);
        $session = $this->newSession(['sku']);

        // Session as constructed has tenant=null until assignTenant() is
        // called — the persistence listener owns that in production.
        $this->expectException(LogicException::class);
        iterator_to_array($builder->build([$this->newProduct('TST-001')], $session));
    }

    #[Test]
    public function rendersBuiltInSkuAndParentSkuColumns(): void
    {
        $master = $this->newProduct('MST-001');
        $variant = $this->newProduct('VAR-001', $master);

        $builder = $this->newBuilder(
            valuesByObject: [],
            categoriesByObject: [],
        );

        $session = $this->newSessionWithTenant(['sku', 'parent_sku']);
        $rows = iterator_to_array($builder->build([$master, $variant], $session));

        self::assertCount(2, $rows);
        self::assertSame(['sku' => 'MST-001', 'parent_sku' => ''], $rows[0]);
        self::assertSame(['sku' => 'VAR-001', 'parent_sku' => 'MST-001'], $rows[1]);
    }

    #[Test]
    public function rendersAttributeColumnsWithLocaleScoping(): void
    {
        $product = $this->newProduct('SKU-A');
        $descAttribute = $this->newAttribute('description', AttributeType::Text);

        $valuesByObject = [
            spl_object_id($product) => [
                new ObjectValue($product, $descAttribute, ['value' => 'Polski opis'], locale: 'pl'),
                new ObjectValue($product, $descAttribute, ['value' => 'English description'], locale: 'en'),
            ],
        ];

        $builder = $this->newBuilder(valuesByObject: $valuesByObject, categoriesByObject: []);
        $session = $this->newSessionWithTenant(['sku', 'description.pl', 'description.en']);
        $rows = iterator_to_array($builder->build([$product], $session));

        self::assertSame([
            'sku' => 'SKU-A',
            'description.pl' => 'Polski opis',
            'description.en' => 'English description',
        ], $rows[0]);
    }

    #[Test]
    public function rendersAttributeColumnsWithChannelScoping(): void
    {
        // #1229 — `description.shopify` resolves to the channel-scoped value;
        // the bare `description` column still emits the global value.
        $product = $this->newProduct('SKU-CH');
        $desc = $this->newAttribute('description', AttributeType::Text);
        $shopifyId = Uuid::v7();

        $valuesByObject = [
            spl_object_id($product) => [
                new ObjectValue($product, $desc, ['value' => 'Global desc']),
                new ObjectValue($product, $desc, ['value' => 'Shopify desc'], channelId: $shopifyId),
            ],
        ];

        $builder = $this->newBuilder(
            valuesByObject: $valuesByObject,
            categoriesByObject: [],
            channelIds: ['shopify' => $shopifyId],
        );
        $session = $this->newSessionWithTenant(
            ['sku', 'description', 'description.shopify'],
            channels: ['shopify'],
        );
        $rows = iterator_to_array($builder->build([$product], $session));

        self::assertSame([
            'sku' => 'SKU-CH',
            'description' => 'Global desc',
            'description.shopify' => 'Shopify desc',
        ], $rows[0]);
    }

    #[Test]
    public function rendersCombinedLocaleAndChannelColumn(): void
    {
        // IMP2-1.6 (#1469) — `description.en.shopify` looks up the value
        // scoped to BOTH locale en and channel shopify (en is non-primary so
        // no fan-out interferes).
        $product = $this->newProduct('SKU-LC');
        $desc = $this->newAttribute('description', AttributeType::Text);
        $shopifyId = Uuid::v7();

        $valuesByObject = [
            spl_object_id($product) => [
                new ObjectValue($product, $desc, ['value' => 'EN Shopify'], channelId: $shopifyId, locale: 'en'),
                new ObjectValue($product, $desc, ['value' => 'Global']),
            ],
        ];

        $builder = $this->newBuilder(
            valuesByObject: $valuesByObject,
            categoriesByObject: [],
            channelIds: ['shopify' => $shopifyId],
        );
        $session = $this->newSessionWithTenant(['description.en.shopify'], channels: ['shopify']);
        $rows = iterator_to_array($builder->build([$product], $session));

        self::assertSame(['description.en.shopify' => 'EN Shopify'], $rows[0]);
    }

    #[Test]
    public function fansOutPrimaryLocaleColumnToGlobalValue(): void
    {
        // #1146 — the writer stores the PRIMARY locale (pl) as the global
        // row, so a `name.pl` column must fall back to that global value
        // instead of a blank cell. A non-primary `name.en` with no row stays
        // blank (no leak of the primary value).
        $product = $this->newProduct('SKU-FO');
        $name = $this->newAttribute('name', AttributeType::Text);

        $valuesByObject = [
            spl_object_id($product) => [
                new ObjectValue($product, $name, ['value' => 'Globalna nazwa']),
            ],
        ];

        $builder = $this->newBuilder(valuesByObject: $valuesByObject, categoriesByObject: []);
        // Tenant primary locale defaults to 'pl'.
        $session = $this->newSessionWithTenant(['sku', 'name.pl', 'name.en']);
        $rows = iterator_to_array($builder->build([$product], $session));

        self::assertSame([
            'sku' => 'SKU-FO',
            'name.pl' => 'Globalna nazwa',
            'name.en' => '',
        ], $rows[0]);
    }

    #[Test]
    public function throwsWhenChannelColumnReferencesUnresolvableChannel(): void
    {
        // IMP2-1.6 (R-47) — a stale channel column no longer degrades to a
        // silent blank; the export fails loudly so clear_if_empty downstream
        // cannot wipe a destination from an empty file.
        $product = $this->newProduct('SKU-STALE');

        $builder = $this->newBuilder(
            valuesByObject: [spl_object_id($product) => []],
            categoriesByObject: [],
            channelIds: [],
        );
        $session = $this->newSessionWithTenant(['sku', 'description.allegro'], channels: ['allegro']);

        $this->expectException(UnresolvedExportChannelException::class);
        iterator_to_array($builder->build([$product], $session));
    }

    #[Test]
    public function missingAttributeYieldsBlankCell(): void
    {
        // R-47 from PRD §14 — profile references attribute that does not
        // exist on the row. Builder MUST NOT 500 — emit blank cell.
        $product = $this->newProduct('SKU-B');

        $builder = $this->newBuilder(
            valuesByObject: [spl_object_id($product) => []],
            categoriesByObject: [],
        );
        $session = $this->newSessionWithTenant(['sku', 'does_not_exist', 'description.pl']);
        $rows = iterator_to_array($builder->build([$product], $session));

        self::assertSame(['sku' => 'SKU-B', 'does_not_exist' => '', 'description.pl' => ''], $rows[0]);
    }

    #[Test]
    public function multiselectAttributeSerialisesWithPipe(): void
    {
        // PRD §8.2 — round-trip default. Pairs with IMP-17 (#603).
        $product = $this->newProduct('SKU-C');
        $tags = $this->newAttribute('tags', AttributeType::Multiselect);

        $builder = $this->newBuilder(
            valuesByObject: [
                spl_object_id($product) => [
                    new ObjectValue($product, $tags, ['option_codes' => ['promo', 'bestseller']]),
                ],
            ],
            categoriesByObject: [],
        );
        $session = $this->newSessionWithTenant(['sku', 'tags']);
        $rows = iterator_to_array($builder->build([$product], $session));

        self::assertSame(['sku' => 'SKU-C', 'tags' => 'promo|bestseller'], $rows[0]);
    }

    #[Test]
    public function categoryColumnConcatenatesCategoryCodes(): void
    {
        $product = $this->newProduct('SKU-D');
        $catA = $this->newProduct('CAT-A', null, ObjectKind::Category);
        $catB = $this->newProduct('CAT-B', null, ObjectKind::Category);

        $assignmentA = $this->createStub(ObjectCategory::class);
        $assignmentA->method('getCategory')->willReturn($catA);
        $assignmentB = $this->createStub(ObjectCategory::class);
        $assignmentB->method('getCategory')->willReturn($catB);

        $builder = $this->newBuilder(
            valuesByObject: [],
            categoriesByObject: [spl_object_id($product) => [$assignmentA, $assignmentB]],
        );
        $session = $this->newSessionWithTenant(['sku', 'category']);
        $rows = iterator_to_array($builder->build([$product], $session));

        self::assertSame(['sku' => 'SKU-D', 'category' => 'CAT-A|CAT-B'], $rows[0]);
    }

    #[Test]
    public function rendersRelationColumnAsPipeJoinedTargetCodes(): void
    {
        // IMP2-1.8 (D5) — a Relation column emits pipe-joined target CODES
        // read from object_relations, not a UUID from ObjectValue.
        $product = $this->newProduct('SKU-R');
        $related = $this->newAttribute('related', AttributeType::Relation);
        $targetA = $this->newProduct('REL-A');
        $targetB = $this->newProduct('REL-B');

        $relA = $this->createStub(ObjectRelation::class);
        $relA->method('getTarget')->willReturn($targetA);
        $relB = $this->createStub(ObjectRelation::class);
        $relB->method('getTarget')->willReturn($targetB);

        $builder = $this->newBuilder(
            valuesByObject: [],
            categoriesByObject: [],
            relationsBySource: [spl_object_id($product) => [$relA, $relB]],
            attributesByCode: ['related' => $related],
        );
        $session = $this->newSessionWithTenant(['sku', 'related']);
        $rows = iterator_to_array($builder->build([$product], $session));

        self::assertSame(['sku' => 'SKU-R', 'related' => 'REL-A|REL-B'], $rows[0]);
    }

    #[Test]
    public function exportsRestrictedAttributeUnderSystemContextWithoutEnforcement(): void
    {
        // AUD-008 (#1578) regression: the sync runner (EXP-05) / async handler
        // (EXP-06) reach the builder from a system context with no security
        // token — isAttributePermissionEnforced() is false. The
        // anonymous-→-restricted default of canViewAttribute() must NOT blank
        // cells there, or a legitimate export comes out empty (CI #1620).
        $product = $this->newProduct('SKU-SYS');
        $restrictedId = Uuid::v7();
        $priceAttr = $this->newAttribute('purchase_price', AttributeType::Number, $restrictedId);

        $valuesByObject = [
            spl_object_id($product) => [
                new ObjectValue($product, $priceAttr, ['value' => 19.99]),
            ],
        ];

        $builder = $this->newBuilder(
            valuesByObject: $valuesByObject,
            categoriesByObject: [],
            attributesByCode: ['purchase_price' => $priceAttr],
            // canViewAttribute() would return false for this id, but
            // enforcement is off → the value still exports.
            restrictedAttrIds: [$restrictedId->toRfc4122()],
            permissionsEnforced: false,
        );
        $session = $this->newSessionWithTenant(['sku', 'purchase_price']);
        $rows = iterator_to_array($builder->build([$product], $session));

        self::assertSame(['sku' => 'SKU-SYS', 'purchase_price' => '19.99'], $rows[0]);
    }

    #[Test]
    public function blanksAttributeColumnTheCallerMayNotView(): void
    {
        // AUD-008 (#1578) — a column whose attribute the caller cannot view
        // (3-state per-attribute permission, PRD §3.5) must export as a blank
        // cell, never the real value. A visible attribute on the same row is
        // untouched.
        $product = $this->newProduct('SKU-SEC');
        $restrictedId = Uuid::v7();
        $priceAttr = $this->newAttribute('purchase_price', AttributeType::Number, $restrictedId);
        $descAttr = $this->newAttribute('description', AttributeType::Text);

        $valuesByObject = [
            spl_object_id($product) => [
                new ObjectValue($product, $priceAttr, ['value' => 19.99]),
                new ObjectValue($product, $descAttr, ['value' => 'Visible copy']),
            ],
        ];

        $builder = $this->newBuilder(
            valuesByObject: $valuesByObject,
            categoriesByObject: [],
            attributesByCode: ['purchase_price' => $priceAttr, 'description' => $descAttr],
            restrictedAttrIds: [$restrictedId->toRfc4122()],
        );
        $session = $this->newSessionWithTenant(['sku', 'purchase_price', 'description']);
        $rows = iterator_to_array($builder->build([$product], $session));

        self::assertSame(
            ['sku' => 'SKU-SEC', 'purchase_price' => '', 'description' => 'Visible copy'],
            $rows[0],
        );
    }

    #[Test]
    public function batchPrefetchesValuesRelationsAndCategoriesOncePerPageNotPerObject(): void
    {
        // AUD-016 (#1632) — the builder must batch-load a PAGE of objects in a
        // fixed number of queries (one values batch, one relations batch per
        // relation column, one categories batch), NOT one query per object.
        // We spy on the batch repo methods and assert the call counts do not
        // scale with the page size (3 objects → still 1 + 1 + 1).
        $related = $this->newAttribute('related', AttributeType::Relation);
        $products = [$this->newProduct('SKU-1'), $this->newProduct('SKU-2'), $this->newProduct('SKU-3')];

        $valuesCalls = 0;
        $relationsCalls = 0;
        $categoriesCalls = 0;
        $perObjectValueCalls = 0;
        $perObjectRelationCalls = 0;
        $perObjectCategoryCalls = 0;

        $values = $this->createStub(ObjectValueRepositoryInterface::class);
        $values->method('findByObjectIds')->willReturnCallback(static function (array $ids) use (&$valuesCalls): array {
            ++$valuesCalls;
            self::assertCount(3, $ids, 'the whole page is batched in one call');

            return [];
        });
        $values->method('findByObject')->willReturnCallback(static function () use (&$perObjectValueCalls): array {
            ++$perObjectValueCalls;

            return [];
        });

        $relations = $this->createStub(ObjectRelationRepositoryInterface::class);
        $relations->method('findBySourceIdsAndAttribute')->willReturnCallback(static function (array $ids) use (&$relationsCalls): array {
            ++$relationsCalls;
            self::assertCount(3, $ids, 'relations are batched for the whole page');

            return [];
        });
        $relations->method('findBySourceAndAttribute')->willReturnCallback(static function () use (&$perObjectRelationCalls): array {
            ++$perObjectRelationCalls;

            return [];
        });

        $categories = $this->createStub(ObjectCategoryRepositoryInterface::class);
        $categories->method('findByProductIds')->willReturnCallback(static function (array $ids) use (&$categoriesCalls): array {
            ++$categoriesCalls;
            self::assertCount(3, $ids, 'categories are batched for the whole page');

            return [];
        });
        $categories->method('findByProduct')->willReturnCallback(static function () use (&$perObjectCategoryCalls): array {
            ++$perObjectCategoryCalls;

            return [];
        });

        $channels = $this->createStub(ChannelResolverInterface::class);
        $attributes = $this->createStub(AttributeRepositoryInterface::class);
        $attributes->method('findByCode')->willReturnCallback(
            static fn (string $code): ?Attribute => 'related' === $code ? $related : null
        );
        $permissions = $this->createStub(\App\Identity\Contracts\Policy\AttributePermissionReader::class);
        $permissions->method('isAttributePermissionEnforced')->willReturn(false);
        $permissions->method('canViewAttribute')->willReturn(true);

        $builder = new ExportBuilder(
            values: $values,
            categories: $categories,
            columnResolver: new ColumnResolver(),
            serializer: new ValueSerializer(),
            channels: $channels,
            relations: $relations,
            attributes: $attributes,
            attributePermissions: $permissions,
        );
        $session = $this->newSessionWithTenant(['sku', 'related', 'category']);

        iterator_to_array($builder->build($products, $session));

        self::assertSame(1, $valuesCalls, 'object_values prefetched ONCE for the 3-object page');
        self::assertSame(1, $relationsCalls, 'relations prefetched ONCE per relation column for the page');
        self::assertSame(1, $categoriesCalls, 'categories prefetched ONCE for the page');
        // The pre-1632 per-object path must be gone entirely.
        self::assertSame(0, $perObjectValueCalls, 'no per-object findByObject (N+1 removed)');
        self::assertSame(0, $perObjectRelationCalls, 'no per-object findBySourceAndAttribute (N+1 removed)');
        self::assertSame(0, $perObjectCategoryCalls, 'no per-object findByProduct (N+1 removed)');
    }

    // ----- helpers -----

    /**
     * @param array<int, list<ObjectValue>>    $valuesByObject      keyed by spl_object_id($product)
     * @param array<int, list<ObjectCategory>> $categoriesByObject  same
     * @param array<string, Uuid>              $channelIds          channel code => id (#1229)
     * @param array<int, list<ObjectRelation>> $relationsBySource   keyed by spl_object_id($source) (#1471)
     * @param array<string, Attribute>         $attributesByCode    attribute column code => Attribute (#1471)
     * @param list<string>                     $restrictedAttrIds   attribute UUIDs the caller may NOT view (AUD-008 #1578)
     * @param bool                             $permissionsEnforced whether a domain user is present so per-attribute grants apply (AUD-008 #1578)
     */
    private function newBuilder(
        array $valuesByObject,
        array $categoriesByObject,
        array $channelIds = [],
        array $relationsBySource = [],
        array $attributesByCode = [],
        array $restrictedAttrIds = [],
        bool $permissionsEnforced = true,
    ): ExportBuilder {
        $resolve = fn (string $id): ?CatalogObject => $this->objectsById[$id] ?? null;

        // #1632 — the builder batches per page: findByObjectIds(list<Uuid>) →
        // map keyed by object UUID. Translate the spl_object_id-keyed fixtures
        // through the id registry.
        $values = $this->createStub(ObjectValueRepositoryInterface::class);
        $values->method('findByObjectIds')->willReturnCallback(
            /** @param list<Uuid> $ids */
            static function (array $ids) use ($valuesByObject, $resolve): array {
                $map = [];
                foreach ($ids as $uuid) {
                    \assert($uuid instanceof Uuid);
                    $rfc = $uuid->toRfc4122();
                    $object = $resolve($rfc);
                    $map[$rfc] = null !== $object ? ($valuesByObject[spl_object_id($object)] ?? []) : [];
                }

                return $map;
            }
        );

        $categories = $this->createStub(ObjectCategoryRepositoryInterface::class);
        $categories->method('findByProductIds')->willReturnCallback(
            /** @param list<string> $ids */
            static function (array $ids) use ($categoriesByObject, $resolve): array {
                $map = [];
                foreach ($ids as $rfc) {
                    \assert(\is_string($rfc));
                    $object = $resolve($rfc);
                    if (null !== $object && [] !== ($categoriesByObject[spl_object_id($object)] ?? [])) {
                        $map[$rfc] = $categoriesByObject[spl_object_id($object)];
                    }
                }

                return $map;
            }
        );

        $channels = $this->createStub(ChannelResolverInterface::class);
        $channels->method('resolveId')->willReturnCallback(
            static fn (string $code): ?Uuid => $channelIds[$code] ?? null
        );

        $relations = $this->createStub(ObjectRelationRepositoryInterface::class);
        $relations->method('findBySourceIdsAndAttribute')->willReturnCallback(
            /** @param list<string> $ids */
            static function (array $ids) use ($relationsBySource, $resolve): array {
                $map = [];
                foreach ($ids as $rfc) {
                    \assert(\is_string($rfc));
                    $object = $resolve($rfc);
                    if (null !== $object && [] !== ($relationsBySource[spl_object_id($object)] ?? [])) {
                        $map[$rfc] = $relationsBySource[spl_object_id($object)];
                    }
                }

                return $map;
            }
        );

        $attributes = $this->createStub(AttributeRepositoryInterface::class);
        $attributes->method('findByCode')->willReturnCallback(
            static fn (string $code): ?Attribute => $attributesByCode[$code] ?? null
        );

        $permissions = $this->createStub(\App\Identity\Contracts\Policy\AttributePermissionReader::class);
        $permissions->method('canViewAttribute')->willReturnCallback(
            static fn (Uuid $id): bool => !\in_array($id->toRfc4122(), $restrictedAttrIds, true)
        );
        $permissions->method('canEditAttribute')->willReturn(true);
        $permissions->method('isAttributePermissionEnforced')->willReturn($permissionsEnforced);

        return new ExportBuilder(
            values: $values,
            categories: $categories,
            columnResolver: new ColumnResolver(),
            serializer: new ValueSerializer(),
            channels: $channels,
            relations: $relations,
            attributes: $attributes,
            attributePermissions: $permissions,
        );
    }

    private function newProduct(string $code, ?CatalogObject $parent = null, ObjectKind $kind = ObjectKind::Product): CatalogObject
    {
        $objectType = $this->createStub(ObjectType::class);
        $objectType->method('getKind')->willReturn($kind);

        $object = new CatalogObject(
            objectType: $objectType,
            code: $code,
        );
        if (null !== $parent) {
            $object->assignParent($parent);
        }
        $this->objectsById[$object->getId()->toRfc4122()] = $object;

        return $object;
    }

    private function newAttribute(string $code, AttributeType $type, ?Uuid $id = null): Attribute
    {
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getCode')->willReturn($code);
        $attribute->method('getType')->willReturn($type);
        $attribute->method('getId')->willReturn($id ?? Uuid::v7());

        return $attribute;
    }

    /**
     * @param list<string>      $selectedColumns
     * @param list<string>|null $channels
     */
    private function newSession(array $selectedColumns, ?array $channels = null): ExportSession
    {
        return new ExportSession(
            userId: Uuid::v7(),
            source: ExportSource::ListContext,
            format: ExportFormat::Xlsx,
            targetScope: ExportTargetScope::Selected,
            selectedColumns: $selectedColumns,
            channels: $channels,
        );
    }

    /**
     * @param list<string>      $selectedColumns
     * @param list<string>|null $channels
     */
    private function newSessionWithTenant(array $selectedColumns, ?array $channels = null): ExportSession
    {
        $session = $this->newSession($selectedColumns, $channels);
        $session->assignTenant(new Tenant('demo', 'Demo'));

        return $session;
    }
}
