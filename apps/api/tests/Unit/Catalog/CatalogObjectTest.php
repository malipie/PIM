<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Shared\Domain\Tenant;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CatalogObjectTest extends TestCase
{
    #[Test]
    public function constructorDefaultsAndUuidV7(): void
    {
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);
        $object = new CatalogObject($type, 'SKU-001');

        self::assertInstanceOf(Uuid::class, $object->getId());
        self::assertSame('SKU-001', $object->getCode());
        self::assertSame(ObjectKind::Product, $object->getKind());
        self::assertSame($type, $object->getObjectType());
        self::assertNull($object->getParent());
        self::assertNull($object->getPath());
        self::assertNull($object->getTenant());
        self::assertTrue($object->isEnabled());
        self::assertSame(CatalogObject::STATUS_DRAFT, $object->getStatus());
        self::assertSame([], $object->getCompleteness());
        self::assertSame([], $object->getAttributesIndexed());
    }

    #[Test]
    public function kindIsDenormalisedFromObjectType(): void
    {
        $categoryType = new ObjectType('category', ObjectKind::Category, ['pl' => 'Kategoria']);
        $object = new CatalogObject($categoryType, 'electronics');

        self::assertSame(ObjectKind::Category, $object->getKind());
        // Object kind tracks ObjectType kind — admins never set it directly.
    }

    #[Test]
    public function setAttributesIndexedRoundTripsJsonbPayload(): void
    {
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);
        $object = new CatalogObject($type, 'SKU-002');

        $object->updateAttributeIndex([
            'name' => ['pl' => 'Smartfon', 'en' => 'Smartphone'],
            'sku' => 'SKU-002',
            'color' => 'red',
            'price' => ['amount' => 1999, 'currency' => 'PLN'],
        ]);

        $indexed = $object->getAttributesIndexed();
        self::assertSame('red', $indexed['color']);
        self::assertIsArray($indexed['name']);
        self::assertSame('Smartfon', $indexed['name']['pl']);
        self::assertIsArray($indexed['price']);
        self::assertSame(1999, $indexed['price']['amount']);
    }

    #[Test]
    public function setPathStoresLtreeStringForCategory(): void
    {
        $categoryType = new ObjectType('category', ObjectKind::Category, ['pl' => 'Kategoria']);
        $object = new CatalogObject($categoryType, 'shoes');

        $object->attachToPath('root.men.shoes');

        self::assertSame('root.men.shoes', $object->getPath());
        // The kind = 'category' OR path IS NULL invariant + ltree validation
        // land in #33 (Doctrine listener) — entity layer just stores text.
    }

    #[Test]
    public function parentSelfReferenceWorksForVariantAndTreePatterns(): void
    {
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);
        $parent = new CatalogObject($type, 'PARENT-SKU');
        $variant = new CatalogObject($type, 'VARIANT-SKU');

        $variant->assignParent($parent);

        self::assertSame($parent, $variant->getParent());
    }

    #[Test]
    public function assignTenantStampsAndRefusesReassignment(): void
    {
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);
        $object = new CatalogObject($type, 'SKU-003');
        $first = new Tenant('demo', 'Demo');
        $second = new Tenant('acme', 'Acme');

        $object->assignTenant($first);
        self::assertSame($first, $object->getTenant());

        $this->expectException(LogicException::class);
        $object->assignTenant($second);
    }

    #[Test]
    public function statusTransitionsAreFreeFormForNow(): void
    {
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);
        $object = new CatalogObject($type, 'SKU-004');

        $object->forceStatus(CatalogObject::STATUS_PUBLISHED);
        self::assertSame('published', $object->getStatus());

        $object->forceStatus(CatalogObject::STATUS_ARCHIVED);
        self::assertSame('archived', $object->getStatus());
    }

    #[Test]
    public function completenessPctDefaultsToZero(): void
    {
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);
        $object = new CatalogObject($type, 'SKU-CMPL-1');

        self::assertSame(0, $object->getCompletenessPct());
    }

    #[Test]
    public function recordCompletenessMirrorsGlobalIntoCompletenessPct(): void
    {
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);
        $object = new CatalogObject($type, 'SKU-CMPL-2');

        $object->recordCompleteness(['global' => 60]);

        self::assertSame(60, $object->getCompletenessPct());
        self::assertSame(['global' => 60], $object->getCompleteness());
    }

    #[Test]
    public function recordCompletenessClampsOutOfRangeGlobalValues(): void
    {
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);
        $object = new CatalogObject($type, 'SKU-CMPL-3');

        $object->recordCompleteness(['global' => 250]);
        self::assertSame(100, $object->getCompletenessPct());

        $object->recordCompleteness(['global' => -25]);
        self::assertSame(0, $object->getCompletenessPct());
    }

    #[Test]
    public function recordCompletenessIgnoresNonIntegerGlobal(): void
    {
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);
        $object = new CatalogObject($type, 'SKU-CMPL-4');

        $object->recordCompleteness(['global' => 80]);
        $object->recordCompleteness(['per_channel' => ['shopify' => 50]]); // no `global` key

        // Last-good-value semantics — ignoring a payload without `global`
        // beats overwriting with 0 and surprising the list view.
        self::assertSame(80, $object->getCompletenessPct());
    }

    #[Test]
    public function syncStatusAggregateDefaultsToGray(): void
    {
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);
        $object = new CatalogObject($type, 'SKU-SYNC-1');

        self::assertSame('gray', $object->getSyncStatusAggregate());
    }

    #[Test]
    public function recordSyncStatusAggregateAcceptsAllowedValues(): void
    {
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);
        $object = new CatalogObject($type, 'SKU-SYNC-2');

        foreach (['green', 'yellow', 'red', 'gray'] as $status) {
            $object->recordSyncStatusAggregate($status);
            self::assertSame($status, $object->getSyncStatusAggregate());
        }
    }

    #[Test]
    public function recordSyncStatusAggregateRejectsUnknownValue(): void
    {
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);
        $object = new CatalogObject($type, 'SKU-SYNC-3');

        $this->expectException(LogicException::class);
        $object->recordSyncStatusAggregate('purple');
    }
}
