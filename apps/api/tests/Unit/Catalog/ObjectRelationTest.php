<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectRelation;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\RelationCardinality;
use App\Shared\Domain\Tenant;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ObjectRelationTest extends TestCase
{
    #[Test]
    public function freshRelationCarriesUuidV7AndDefaults(): void
    {
        $relation = $this->newRelation();

        self::assertInstanceOf(Uuid::class, $relation->getId());
        self::assertSame(0, $relation->getPosition());
        self::assertSame([], $relation->getMetadata());
        self::assertNull($relation->getTenant());
    }

    #[Test]
    public function relationCannotConnectAnObjectToItself(): void
    {
        $tenant = new Tenant('demo', 'Demo');
        $productType = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $product = new CatalogObject($productType, 'SKU-1');
        $product->assignTenant($tenant);
        $attribute = $this->relationAttribute();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('itself');
        new ObjectRelation($product, $product, $attribute);
    }

    #[Test]
    public function reorderUpdatesPosition(): void
    {
        $relation = $this->newRelation();

        $relation->reorder(7);

        self::assertSame(7, $relation->getPosition());
    }

    #[Test]
    public function metadataRoundTripsThroughUpdate(): void
    {
        $relation = $this->newRelation();

        $relation->updateMetadata(['priority' => 1, 'recommended' => true]);

        self::assertSame(['priority' => 1, 'recommended' => true], $relation->getMetadata());
    }

    #[Test]
    public function assignTenantStampsAndRefusesReassignment(): void
    {
        $first = new Tenant('alpha', 'Alpha');
        $second = new Tenant('beta', 'Beta');
        $relation = $this->newRelation();

        $relation->assignTenant($first);

        self::assertSame($first, $relation->getTenant());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('already assigned');
        $relation->assignTenant($second);
    }

    private function newRelation(): ObjectRelation
    {
        $tenant = new Tenant('demo', 'Demo');
        $productType = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $source = new CatalogObject($productType, 'SKU-A');
        $target = new CatalogObject($productType, 'SKU-B');
        $source->assignTenant($tenant);
        $target->assignTenant($tenant);

        return new ObjectRelation($source, $target, $this->relationAttribute());
    }

    private function relationAttribute(): Attribute
    {
        $attribute = new Attribute('up_sell', ['en' => 'Up-sell'], AttributeType::Relation);
        $attribute->setRelationCardinality(RelationCardinality::Many);

        return $attribute;
    }
}
