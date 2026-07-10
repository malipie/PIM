<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Contracts\Event\ObjectArchived;
use App\Catalog\Contracts\Event\ObjectAttributesChanged;
use App\Catalog\Contracts\Event\ObjectCreated;
use App\Catalog\Contracts\Event\ObjectEnabledChanged;
use App\Catalog\Contracts\Event\ObjectPublished;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CatalogObjectEventsTest extends TestCase
{
    #[Test]
    public function assignTenantRecordsObjectCreated(): void
    {
        $object = $this->newObject();

        $tenant = new Tenant('demo', 'Demo');
        $object->assignTenant($tenant);

        $events = $object->pullEvents();
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertInstanceOf(ObjectCreated::class, $event);
        self::assertSame($object->getId()->toRfc4122(), $event->aggregateId());
        self::assertSame(ObjectKind::Product, $event->kind);
        self::assertSame($tenant->getId(), $event->tenantId);
    }

    #[Test]
    public function transitionToPublishedRecordsObjectPublished(): void
    {
        $object = $this->newObjectWithTenant();
        $object->pullEvents();

        $object->forceStatus(CatalogObject::STATUS_PUBLISHED);

        $events = $object->pullEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(ObjectPublished::class, $events[0]);
    }

    #[Test]
    public function transitionToArchivedRecordsObjectArchived(): void
    {
        $object = $this->newObjectWithTenant();
        $object->pullEvents();

        $object->forceStatus(CatalogObject::STATUS_ARCHIVED);

        $events = $object->pullEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(ObjectArchived::class, $events[0]);
    }

    #[Test]
    public function transitionToSameStatusEmitsNothing(): void
    {
        $object = $this->newObjectWithTenant();
        $object->pullEvents();

        $object->forceStatus(CatalogObject::STATUS_DRAFT);

        self::assertSame([], $object->pullEvents());
    }

    #[Test]
    public function changeEnabledRecordsEvent(): void
    {
        $object = $this->newObjectWithTenant();
        $object->pullEvents();

        $object->changeEnabled(false);

        $events = $object->pullEvents();
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertInstanceOf(ObjectEnabledChanged::class, $event);
        self::assertFalse($event->enabled);
    }

    #[Test]
    public function changeEnabledNoOpEmitsNothing(): void
    {
        $object = $this->newObjectWithTenant();
        $object->pullEvents();

        $object->changeEnabled(true); // already true on construction

        self::assertSame([], $object->pullEvents());
    }

    #[Test]
    public function updateAttributeIndexEmitsChangedCodes(): void
    {
        $object = $this->newObjectWithTenant();
        $object->updateAttributeIndex(['sku' => 'A1', 'color' => 'red']);
        $object->pullEvents();

        $object->updateAttributeIndex(['sku' => 'A1', 'color' => 'blue', 'name' => ['pl' => 'X']]);

        $events = $object->pullEvents();
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertInstanceOf(ObjectAttributesChanged::class, $event);
        self::assertEqualsCanonicalizing(['color', 'name'], $event->changedAttributeCodes);
    }

    private function newObject(): CatalogObject
    {
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);

        return new CatalogObject($type, 'TEST-001');
    }

    private function newObjectWithTenant(): CatalogObject
    {
        $object = $this->newObject();
        $object->assignTenant(new Tenant('demo', 'Demo'));

        return $object;
    }
}
