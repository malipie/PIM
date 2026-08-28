<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Provenance;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ObjectValueTest extends TestCase
{
    #[Test]
    public function constructorWiresUpReferencesAndDefaults(): void
    {
        [$object, $attribute] = $this->fixture();

        $value = new ObjectValue($object, $attribute, ['value' => 'red']);

        self::assertInstanceOf(Uuid::class, $value->getId());
        self::assertSame($object, $value->getObject());
        self::assertSame($attribute, $value->getAttribute());
        self::assertSame(['value' => 'red'], $value->getValue());
        self::assertSame(Provenance::Manual, $value->getProvenance());
        self::assertSame([], $value->getProvenanceMeta());
        self::assertNull($value->getChannelId());
        self::assertNull($value->getLocale());
    }

    #[Test]
    public function provenanceCanBeOverriddenAtConstruction(): void
    {
        [$object, $attribute] = $this->fixture();

        $value = new ObjectValue($object, $attribute, ['value' => 'imported'], Provenance::Import);

        self::assertSame(Provenance::Import, $value->getProvenance());
    }

    #[Test]
    public function jsonbValueAcceptsPolymorphicShapesPerAttributeType(): void
    {
        [$object, $attribute] = $this->fixture();

        // select shape
        $value = new ObjectValue($object, $attribute, ['option_code' => 'red']);
        self::assertSame('red', $value->getValue()['option_code']);

        // price shape
        $value = new ObjectValue($object, $attribute, ['amount' => 19.99, 'currency' => 'PLN']);
        self::assertSame('PLN', $value->getValue()['currency']);

        // multiselect shape
        $value = new ObjectValue($object, $attribute, ['option_codes' => ['red', 'blue']]);
        self::assertSame(['red', 'blue'], $value->getValue()['option_codes']);
    }

    #[Test]
    public function scopeColumnsHoldChannelAndLocale(): void
    {
        [$object, $attribute] = $this->fixture();
        $channelId = Uuid::v7();

        $value = new ObjectValue(
            $object,
            $attribute,
            ['value' => 'Polish description'],
            Provenance::Manual,
            $channelId,
            'pl',
        );

        self::assertSame($channelId, $value->getChannelId());
        self::assertSame('pl', $value->getLocale());
    }

    #[Test]
    public function provenanceMetaIsArbitraryJsonbPayload(): void
    {
        [$object, $attribute] = $this->fixture();

        $value = new ObjectValue($object, $attribute, ['value' => 'x'], Provenance::Integration);
        $value->updateProvenanceMeta([
            'integration' => 'shopify',
            'sync_job_id' => '019dd522-6ea8-7e9d-9e63-5a4a782152e6',
        ]);

        self::assertSame('shopify', $value->getProvenanceMeta()['integration']);
    }

    #[Test]
    public function assignTenantRefusesReassignment(): void
    {
        [$object, $attribute] = $this->fixture();
        $value = new ObjectValue($object, $attribute, ['value' => 'x']);

        $first = new \App\Shared\Domain\Tenant('demo', 'Demo');
        $second = new \App\Shared\Domain\Tenant('acme', 'Acme');

        $value->assignTenant($first);
        self::assertSame($first, $value->getTenant());

        $this->expectException(LogicException::class);
        $value->assignTenant($second);
    }

    /**
     * @return array{0: CatalogObject, 1: Attribute}
     */
    private function fixture(): array
    {
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);
        $object = new CatalogObject($type, 'SKU-X');
        $attribute = new Attribute('color', ['pl' => 'Kolor'], AttributeType::Select);

        return [$object, $attribute];
    }
}
