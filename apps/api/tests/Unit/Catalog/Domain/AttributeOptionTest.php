<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeOption;
use App\Catalog\Domain\Exception\InvalidColorFormatException;
use App\Shared\Domain\Tenant;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Unit guard for {@see AttributeOption} (0.11.6 — coverage gap).
 *
 * Asserts the invariants used by the schema-edit flow:
 *   - tenant assignment is single-shot (re-stamping must throw)
 *   - parent Attribute reference is read-only after construction
 *   - id, label, position carry the same semantics as on
 *     {@see \App\Catalog\Domain\Entity\AssociationType}
 */
final class AttributeOptionTest extends TestCase
{
    #[Test]
    public function carriesParentAttributeAndOrdering(): void
    {
        $attribute = self::attribute();
        $option = new AttributeOption($attribute, 'red', ['en' => 'Red'], 3);

        self::assertSame($attribute, $option->getAttribute());
        self::assertSame('red', $option->getCode());
        self::assertSame(['en' => 'Red'], $option->getLabel());
        self::assertSame(3, $option->getPosition());
        self::assertInstanceOf(UuidV7::class, $option->getId(), 'Auto-allocated id is a UUID v7.');
    }

    #[Test]
    public function preservesExplicitIdWhenProvided(): void
    {
        $attribute = self::attribute();
        $explicitId = Uuid::v7();

        $option = new AttributeOption($attribute, 'red', ['en' => 'Red'], 0, $explicitId);

        self::assertTrue($explicitId->equals($option->getId()));
    }

    #[Test]
    public function reassignTenantThrowsToProtectInvariant(): void
    {
        $option = new AttributeOption(self::attribute(), 'red', ['en' => 'Red']);
        $option->assignTenant(new Tenant('demo', 'Demo'));

        $this->expectException(LogicException::class);
        $option->assignTenant(new Tenant('acme', 'Acme'));
    }

    #[Test]
    public function renameReplacesLabelOnly(): void
    {
        $attribute = self::attribute();
        $option = new AttributeOption($attribute, 'red', ['en' => 'Red'], 7);

        $option->rename(['en' => 'Crimson', 'pl' => 'Karmazynowy']);

        self::assertSame(['en' => 'Crimson', 'pl' => 'Karmazynowy'], $option->getLabel());
        self::assertSame('red', $option->getCode());
        self::assertSame($attribute, $option->getAttribute());
        self::assertSame(7, $option->getPosition());
    }

    #[Test]
    public function reorderUpdatesPositionOnly(): void
    {
        $option = new AttributeOption(self::attribute(), 'red', ['en' => 'Red']);

        $option->reorder(99);

        self::assertSame(99, $option->getPosition());
    }

    #[Test]
    public function colorAcceptsHexAndDefaultsToNull(): void
    {
        $option = new AttributeOption(self::attribute(), 'red', ['en' => 'Red']);
        self::assertNull($option->getColor());

        $option->setColor('#FF0000');
        self::assertSame('#FF0000', $option->getColor());

        $option->setColor(null);
        self::assertNull($option->getColor());
    }

    #[Test]
    public function colorRejectsNonHexFormat(): void
    {
        $option = new AttributeOption(self::attribute(), 'red', ['en' => 'Red']);
        $this->expectException(InvalidColorFormatException::class);
        $option->setColor('rgb(255, 0, 0)');
    }

    #[Test]
    public function isDefaultAndIsDeprecatedFlagsRoundTrip(): void
    {
        $option = new AttributeOption(self::attribute(), 'red', ['en' => 'Red'], 0, null, '#FF0000', true, true);

        self::assertTrue($option->isDefault());
        self::assertTrue($option->isDeprecated());

        $option->setDefault(false);
        $option->setDeprecated(false);

        self::assertFalse($option->isDefault());
        self::assertFalse($option->isDeprecated());
    }

    private static function attribute(): Attribute
    {
        return new Attribute('color', ['en' => 'Color'], AttributeType::Select);
    }
}
