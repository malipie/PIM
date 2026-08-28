<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\AttributeGroupAttribute;
use App\Catalog\Domain\Entity\CategoryAttributeGroup;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttributeGroup;
use App\Catalog\Domain\ObjectKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Covers the first-class AttributeGroup data model introduced by ADR-012
 * (#256 / UI-08.1) — extended AttributeGroup metadata + 3 junction
 * entities (attribute / object-type / category × object-type).
 */
final class AttributeGroupFirstClassTest extends TestCase
{
    #[Test]
    public function attributeGroupCarriesNewFirstClassFields(): void
    {
        $group = new AttributeGroup(
            code: 'medical-requirements',
            label: ['pl' => 'Wymagania medyczne', 'en' => 'Medical requirements'],
            position: 4,
            description: ['pl' => 'Atrybuty specyficzne dla usług medycznych', 'en' => 'Medical service attributes'],
            icon: 'stethoscope',
            color: '#EF4444',
            isSystemGroup: false,
            autoAttached: false,
        );

        self::assertSame('medical-requirements', $group->getCode());
        self::assertSame('Wymagania medyczne', $group->getLabel()['pl']);
        self::assertSame(4, $group->getPosition());
        $description = $group->getDescription();
        self::assertNotNull($description);
        self::assertSame('Medical service attributes', $description['en']);
        self::assertSame('stethoscope', $group->getIcon());
        self::assertSame('#EF4444', $group->getColor());
        self::assertFalse($group->isSystemGroup());
        self::assertFalse($group->isAutoAttached());
    }

    #[Test]
    public function systemGroupSurfacesFlags(): void
    {
        $audit = new AttributeGroup(
            code: 'audit',
            label: ['pl' => 'Audyt', 'en' => 'Audit'],
            isSystemGroup: true,
            autoAttached: true,
        );

        self::assertTrue($audit->isSystemGroup());
        self::assertTrue($audit->isAutoAttached());
    }

    #[Test]
    public function attributeGroupAttributeJunctionPreservesPositionAndVisibilityRule(): void
    {
        $group = new AttributeGroup('marketing', ['pl' => 'Marketing', 'en' => 'Marketing']);
        $attribute = new Attribute('description', ['pl' => 'Opis', 'en' => 'Description'], AttributeType::Text);

        $junction = new AttributeGroupAttribute(
            attributeGroup: $group,
            attribute: $attribute,
            position: 7,
            isRequiredInGroup: true,
            visibleWhen: ['field' => 'is_premium', 'operator' => 'equals', 'value' => true],
        );

        self::assertSame($group, $junction->getAttributeGroup());
        self::assertSame($attribute, $junction->getAttribute());
        self::assertSame(7, $junction->getPosition());
        self::assertTrue($junction->isRequiredInGroup());
        $rule = $junction->getVisibleWhen();
        self::assertNotNull($rule);
        self::assertSame('is_premium', $rule['field']);
    }

    #[Test]
    public function attributeGroupAttributeReorderAndRequiredAreMutable(): void
    {
        $group = new AttributeGroup('marketing', ['pl' => 'Marketing']);
        $attribute = new Attribute('description', ['pl' => 'Opis'], AttributeType::Text);

        $junction = new AttributeGroupAttribute($group, $attribute);
        self::assertSame(0, $junction->getPosition());
        self::assertFalse($junction->isRequiredInGroup());
        self::assertNull($junction->getVisibleWhen());

        $junction->reorder(2);
        $junction->changeRequiredInGroup(true);
        $junction->changeVisibleWhen(['field' => 'enabled', 'operator' => 'equals', 'value' => true]);

        self::assertSame(2, $junction->getPosition());
        self::assertTrue($junction->isRequiredInGroup());
        self::assertNotNull($junction->getVisibleWhen());
    }

    #[Test]
    public function objectTypeAttributeGroupJunctionWiresGlobalGroup(): void
    {
        $service = new ObjectType('service', ObjectKind::Custom, ['pl' => 'Usługa']);
        $audit = new AttributeGroup('audit', ['pl' => 'Audyt'], isSystemGroup: true, autoAttached: true);

        $junction = new ObjectTypeAttributeGroup($service, $audit, position: 999);

        self::assertSame($service, $junction->getObjectType());
        self::assertSame($audit, $junction->getAttributeGroup());
        self::assertSame(999, $junction->getPosition());

        $junction->reorder(1000);
        self::assertSame(1000, $junction->getPosition());
    }

    #[Test]
    public function categoryAttributeGroupTracksCategoryTargetTypeAndGroup(): void
    {
        $service = new ObjectType('service', ObjectKind::Custom, ['pl' => 'Usługa']);
        $medicalReqs = new AttributeGroup(
            'medical-requirements',
            ['pl' => 'Wymagania medyczne'],
        );
        $categoryId = Uuid::v7();

        $junction = new CategoryAttributeGroup(
            categoryObjectId: $categoryId,
            targetObjectType: $service,
            attributeGroup: $medicalReqs,
            position: 3,
        );

        self::assertSame($categoryId, $junction->getCategoryObjectId());
        self::assertSame($service, $junction->getTargetObjectType());
        self::assertSame($medicalReqs, $junction->getAttributeGroup());
        self::assertSame(3, $junction->getPosition());

        $junction->reorder(5);
        self::assertSame(5, $junction->getPosition());
    }
}
