<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Shared\Domain\Tenant;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ObjectTypeTest extends TestCase
{
    #[Test]
    public function fresherObjectTypeHasUuidV7AndDefaults(): void
    {
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt', 'en' => 'Product']);

        self::assertInstanceOf(Uuid::class, $type->getId());
        self::assertSame('product', $type->getCode());
        self::assertSame(ObjectKind::Product, $type->getKind());
        self::assertSame(['pl' => 'Produkt', 'en' => 'Product'], $type->getLabel());
        self::assertFalse($type->isBuiltIn());
        self::assertSame([], $type->getCompletenessRules());
        self::assertNull($type->getLabelAttribute());
        self::assertNull($type->getImageAttribute());
        self::assertSame(1, $type->getSchemaVersion());
        self::assertNull($type->getTenant());
    }

    #[Test]
    public function jsonbLabelRoundTripsUtf8Polish(): void
    {
        $type = new ObjectType('shoes', ObjectKind::Custom, ['pl' => 'Buty (sezon letni)', 'en' => 'Shoes']);

        self::assertSame('Buty (sezon letni)', $type->getLabel()['pl']);
    }

    #[Test]
    public function markBuiltInTogglesFlag(): void
    {
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);

        $type->markBuiltIn();

        self::assertTrue($type->isBuiltIn());
    }

    #[Test]
    public function setLabelAndImageAttributeStoresReference(): void
    {
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);
        $name = new Attribute('name', ['pl' => 'Nazwa'], AttributeType::Text);
        $image = new Attribute('main_image', ['pl' => 'Zdjęcie'], AttributeType::Asset);

        $type->assignLabelAttribute($name);
        $type->assignImageAttribute($image);

        self::assertSame($name, $type->getLabelAttribute());
        self::assertSame($image, $type->getImageAttribute());
    }

    #[Test]
    public function setCompletenessRulesStoresJsonbPayload(): void
    {
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);

        $type->updateCompletenessRules(['required' => ['sku', 'name'], 'weight' => ['sku' => 2]]);

        self::assertSame(
            ['required' => ['sku', 'name'], 'weight' => ['sku' => 2]],
            $type->getCompletenessRules(),
        );
    }

    #[Test]
    public function bumpSchemaVersionIncrementsCounter(): void
    {
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);

        $type->bumpSchemaVersion();
        $type->bumpSchemaVersion();

        self::assertSame(3, $type->getSchemaVersion());
    }

    #[Test]
    public function assignTenantStampsAndRefusesReassignment(): void
    {
        $first = new Tenant('demo', 'Demo Tenant');
        $second = new Tenant('acme', 'Acme Industries');
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);

        $type->assignTenant($first);

        self::assertSame($first, $type->getTenant());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('already assigned');
        $type->assignTenant($second);
    }

    #[Test]
    public function settingsTogglesDefaultToFalse(): void
    {
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);

        self::assertFalse($type->isHierarchical());
        self::assertFalse($type->hasVariants());
        self::assertFalse($type->getHasVariants());
        self::assertFalse($type->isAbstract());
        self::assertSame([], $type->getAllowedParentTypeIds());
    }

    #[Test]
    public function settingsTogglesAreMutable(): void
    {
        $type = new ObjectType('subscription', ObjectKind::Custom, ['pl' => 'Subskrypcja']);

        $type->setHierarchical(true);
        $type->setHasVariants(true);
        $type->setAbstract(true);
        $type->setAllowedParentTypeIds(['11111111-1111-7111-8111-111111111111', '11111111-1111-7111-8111-111111111111', '22222222-2222-7222-8222-222222222222']);

        self::assertTrue($type->isHierarchical());
        self::assertTrue($type->hasVariants());
        self::assertTrue($type->isAbstract());
        self::assertSame(
            ['11111111-1111-7111-8111-111111111111', '22222222-2222-7222-8222-222222222222'],
            $type->getAllowedParentTypeIds(),
        );
    }

    #[Test]
    public function isCategorizableDefaultsToFalse(): void
    {
        $type = new ObjectType('subscription', ObjectKind::Custom, ['pl' => 'Subskrypcja']);

        self::assertFalse($type->isCategorizable());
        self::assertFalse($type->getIsCategorizable());
    }

    #[Test]
    public function setCategorizableTogglesFlag(): void
    {
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);

        $type->setCategorizable(true);

        self::assertTrue($type->isCategorizable());
        self::assertTrue($type->getIsCategorizable());
    }
}
