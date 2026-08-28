<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\AttributeOption;
use App\Catalog\Domain\RelationCardinality;
use App\Shared\Domain\Tenant;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AttributeTest extends TestCase
{
    #[Test]
    public function fresherAttributeHasUuidV7AndDefaults(): void
    {
        $attribute = new Attribute('color', ['pl' => 'Kolor', 'en' => 'Color'], AttributeType::Select);

        self::assertInstanceOf(Uuid::class, $attribute->getId());
        self::assertSame('color', $attribute->getCode());
        self::assertSame(['pl' => 'Kolor', 'en' => 'Color'], $attribute->getLabel());
        self::assertSame(AttributeType::Select, $attribute->getType());
        self::assertNull($attribute->getHelp());
        self::assertNull($attribute->getGroup());
        self::assertFalse($attribute->isLocalizable());
        self::assertFalse($attribute->isScopable());
        self::assertFalse($attribute->isRequired());
        self::assertSame([], $attribute->getValidationRules());
        self::assertSame(0, $attribute->getPosition());
        self::assertNull($attribute->getTenant());
    }

    #[Test]
    public function jsonbLabelRoundTripsUtf8Polish(): void
    {
        // Smoke for the JSONB → array hydration: polish characters must
        // survive without mojibake. The DB roundtrip is exercised by
        // functional tests later in the epic; this guards the in-memory
        // contract.
        $attribute = new Attribute(
            'price_net',
            ['pl' => 'Cena netto (zł)', 'en' => 'Net price'],
            AttributeType::Price,
        );

        self::assertSame('Cena netto (zł)', $attribute->getLabel()['pl']);
    }

    #[Test]
    public function assignTenantStampsAndIsIdempotentOnce(): void
    {
        $tenant = new Tenant('demo', 'Demo Tenant');
        $attribute = new Attribute('sku', ['pl' => 'SKU', 'en' => 'SKU'], AttributeType::Text);

        $attribute->assignTenant($tenant);

        self::assertSame($tenant, $attribute->getTenant());
    }

    #[Test]
    public function assignTenantRefusesReassignment(): void
    {
        $first = new Tenant('demo', 'Demo Tenant');
        $second = new Tenant('acme', 'Acme Industries');
        $attribute = new Attribute('sku', ['pl' => 'SKU', 'en' => 'SKU'], AttributeType::Text);

        $attribute->assignTenant($first);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('already assigned');
        $attribute->assignTenant($second);
    }

    #[Test]
    public function usesOptionsDelegatesToTypeEnum(): void
    {
        $select = new Attribute('color', ['pl' => 'Kolor'], AttributeType::Select);
        $text = new Attribute('name', ['pl' => 'Nazwa'], AttributeType::Text);

        self::assertTrue($select->usesOptions());
        self::assertFalse($text->usesOptions());
    }

    #[Test]
    public function attributeGroupConstructionDefaultsAreSensible(): void
    {
        $group = new AttributeGroup('seo', ['pl' => 'SEO', 'en' => 'SEO']);

        self::assertSame('seo', $group->getCode());
        self::assertSame(['pl' => 'SEO', 'en' => 'SEO'], $group->getLabel());
        self::assertSame(0, $group->getPosition());
    }

    #[Test]
    public function attributeOptionRequiresParentAttributeAndKeepsReference(): void
    {
        $color = new Attribute('color', ['pl' => 'Kolor'], AttributeType::Select);
        $option = new AttributeOption($color, 'red', ['pl' => 'Czerwony', 'en' => 'Red'], 1);

        self::assertSame($color, $option->getAttribute());
        self::assertSame('red', $option->getCode());
        self::assertSame(1, $option->getPosition());
    }

    #[Test]
    public function relationConfigDefaultsAreUnsetForNonRelationAttribute(): void
    {
        $name = new Attribute('name', ['pl' => 'Nazwa'], AttributeType::Text);

        self::assertSame([], $name->getRelationTargetObjectTypeIds());
        self::assertNull($name->getRelationCardinality());
        self::assertFalse($name->isRelationAdvanced());
    }

    #[Test]
    public function relationConfigStoresTargetCardinalityAdvanced(): void
    {
        $upSell = new Attribute('up_sell', ['pl' => 'Up-sell'], AttributeType::Relation);

        $upSell->setRelationTargetObjectTypeIds([
            '11111111-1111-7111-8111-111111111111',
            '11111111-1111-7111-8111-111111111111',
            '22222222-2222-7222-8222-222222222222',
        ]);
        $upSell->setRelationCardinality(RelationCardinality::Many);
        $upSell->setRelationAdvanced(true);

        self::assertSame(
            [
                '11111111-1111-7111-8111-111111111111',
                '22222222-2222-7222-8222-222222222222',
            ],
            $upSell->getRelationTargetObjectTypeIds(),
        );
        self::assertSame(RelationCardinality::Many, $upSell->getRelationCardinality());
        self::assertTrue($upSell->isRelationAdvanced());
    }

    #[Test]
    public function relationCardinalityCanBeReset(): void
    {
        $brand = new Attribute('brand', ['pl' => 'Marka'], AttributeType::Relation);

        $brand->setRelationCardinality(RelationCardinality::One);
        $brand->setRelationCardinality(null);

        self::assertNull($brand->getRelationCardinality());
    }
}
