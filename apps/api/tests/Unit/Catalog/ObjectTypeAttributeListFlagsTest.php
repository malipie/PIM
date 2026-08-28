<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\Entity\SavedView;
use App\Catalog\Domain\ObjectKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ULV-01 (#982) — covers the additive list-column flags
 * (`show_in_list` + `list_position`) on `ObjectTypeAttribute` and the
 * `objectType` relation on `SavedView`.
 */
final class ObjectTypeAttributeListFlagsTest extends TestCase
{
    #[Test]
    public function freshJunctionDefaultsToHiddenColumnAtPositionZero(): void
    {
        $junction = $this->makeJunction();

        self::assertFalse($junction->isShownInList());
        self::assertFalse($junction->getShowInList());
        self::assertSame(0, $junction->getListPosition());
    }

    #[Test]
    public function showInListTogglesIndependentlyFromSortOrder(): void
    {
        $junction = $this->makeJunction();
        $junction->reorder(7);
        $junction->setShowInList(true);
        $junction->setListPosition(3);

        self::assertTrue($junction->isShownInList());
        self::assertSame(3, $junction->getListPosition());
        self::assertSame(7, $junction->getSortOrder(), 'form sort and list position are independent');
    }

    #[Test]
    public function savedViewStartsWithoutObjectTypeForBackwardCompat(): void
    {
        $view = new SavedView('slug', 'name', 'products', config: []);

        self::assertNull($view->getObjectType());
        self::assertSame('products', $view->getResource());
    }

    #[Test]
    public function assignObjectTypeBindsSavedViewToObjectType(): void
    {
        $view = new SavedView('slug', 'name', 'products', config: []);
        $objectType = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkty']);

        $view->assignObjectType($objectType);

        self::assertSame($objectType, $view->getObjectType());
    }

    private function makeJunction(): ObjectTypeAttribute
    {
        $objectType = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkty']);
        $attribute = new Attribute('name', ['pl' => 'Nazwa'], AttributeType::Text);

        return new ObjectTypeAttribute($objectType, $attribute);
    }
}
