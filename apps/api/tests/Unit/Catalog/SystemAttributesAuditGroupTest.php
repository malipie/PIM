<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Covers system attribute and system AttributeGroup invariants. Runtime
 * seeders create platform-owned audit attributes only; AttributeGroup
 * visibility is explicit modeling configuration.
 */
final class SystemAttributesAuditGroupTest extends TestCase
{
    #[Test]
    public function freshAttributeIsNotSystem(): void
    {
        $attribute = new Attribute('sku', ['en' => 'SKU'], AttributeType::Text);

        self::assertFalse($attribute->isSystem());
    }

    #[Test]
    public function markSystemFlipsTheFlag(): void
    {
        $attribute = new Attribute('created_at', ['en' => 'Created at'], AttributeType::Datetime);
        $attribute->markSystem();

        self::assertTrue($attribute->isSystem());
    }

    #[Test]
    public function systemAttributeRefusesCodeChange(): void
    {
        $attribute = new Attribute('created_at', ['en' => 'Created at'], AttributeType::Datetime);
        $attribute->markSystem();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('System attribute code is immutable.');
        $attribute->changeCode('created_when');
    }

    #[Test]
    public function nonSystemAttributeAllowsCodeChange(): void
    {
        $attribute = new Attribute('sku', ['en' => 'SKU'], AttributeType::Text);
        $attribute->changeCode('product_sku');

        self::assertSame('product_sku', $attribute->getCode());
    }

    #[Test]
    public function onlyReferenceIsASystemType(): void
    {
        // #1177 — `datetime` became a user-facing, validated type; `created_at`/
        // `updated_at` stay read-only via the instance `isSystem` flag (see
        // markSystemFlipsTheFlag), not via the type. Only `reference` remains
        // a system type.
        self::assertTrue(AttributeType::Reference->isSystemType());
        self::assertFalse(AttributeType::Datetime->isSystemType());
        self::assertFalse(AttributeType::Text->isSystemType());
        self::assertFalse(AttributeType::Date->isSystemType());
        self::assertFalse(AttributeType::Relation->isSystemType());
    }

    #[Test]
    public function systemTypesDoNotUseOptions(): void
    {
        self::assertFalse(AttributeType::Datetime->usesOptions());
        self::assertFalse(AttributeType::Reference->usesOptions());
    }

    #[Test]
    public function systemAttributeGroupCodeIsImmutable(): void
    {
        $audit = new AttributeGroup('audit', ['en' => 'Audit'], isSystemGroup: true, autoAttached: true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('System AttributeGroup code is immutable.');
        $audit->changeCode('audit_renamed');
    }

    #[Test]
    public function nonSystemAttributeGroupAllowsCodeChange(): void
    {
        $marketing = new AttributeGroup('marketing', ['en' => 'Marketing']);
        $marketing->changeCode('mkt');

        self::assertSame('mkt', $marketing->getCode());
    }

    #[Test]
    public function systemAttributeGroupAllowsRenameOfTranslatableLabel(): void
    {
        $audit = new AttributeGroup('audit', ['en' => 'Audit'], isSystemGroup: true, autoAttached: true);
        $audit->rename(['en' => 'Audit log', 'pl' => 'Dziennik audytu']);

        self::assertSame('Audit log', $audit->getLabel()['en']);
        self::assertSame('Dziennik audytu', $audit->getLabel()['pl']);
    }
}
