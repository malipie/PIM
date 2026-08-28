<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Application\Builder;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Export\Application\Builder\ValueSerializer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * EXP-03 (#582) — ValueSerializer contract tests.
 *
 * Locks the JSONB-payload → string mapping that powers every export cell.
 * Round-trip-first: every serialised value must be unambiguous on the
 * reimport side (IMP-17/IMP-18/IMP-19 follow-ups).
 */
final class ValueSerializerTest extends TestCase
{
    #[Test]
    public function nullValueSerialisesToBlankCell(): void
    {
        $serializer = new ValueSerializer();
        self::assertSame('', $serializer->serialize(null));
    }

    #[Test]
    public function emptyPayloadSerialisesToBlank(): void
    {
        $serializer = new ValueSerializer();
        $value = $this->valueWithPayload(AttributeType::Text, []);
        self::assertSame('', $serializer->serialize($value));
    }

    #[Test]
    public function textPayloadReturnsValueKey(): void
    {
        $serializer = new ValueSerializer();
        $value = $this->valueWithPayload(AttributeType::Text, ['value' => 'Hello World']);
        self::assertSame('Hello World', $serializer->serialize($value));
    }

    #[Test]
    public function numberPayloadSerialisesAsString(): void
    {
        $serializer = new ValueSerializer();
        $value = $this->valueWithPayload(AttributeType::Number, ['value' => 42.5]);
        self::assertSame('42.5', $serializer->serialize($value));
    }

    #[Test]
    public function booleanPayloadReturnsLiteral(): void
    {
        $serializer = new ValueSerializer();
        $true = $this->valueWithPayload(AttributeType::Boolean, ['value' => true]);
        $false = $this->valueWithPayload(AttributeType::Boolean, ['value' => false]);
        self::assertSame('true', $serializer->serialize($true));
        self::assertSame('false', $serializer->serialize($false));
    }

    #[Test]
    public function multiselectJoinsOptionCodesWithPipe(): void
    {
        // PRD §8.2 — default multi-value serialisation. Round-trip pairs
        // with IMP-17 (#603) pipe-split parser on the reimport side.
        $serializer = new ValueSerializer();
        $value = $this->valueWithPayload(AttributeType::Multiselect, [
            'option_codes' => ['promo', 'nowość', 'bestseller'],
        ]);
        self::assertSame('promo|nowość|bestseller', $serializer->serialize($value));
    }

    #[Test]
    public function multiselectWithNoCodesIsBlank(): void
    {
        $serializer = new ValueSerializer();
        $value = $this->valueWithPayload(AttributeType::Multiselect, ['option_codes' => []]);
        self::assertSame('', $serializer->serialize($value));
    }

    #[Test]
    public function priceCombinesAmountAndCurrency(): void
    {
        $serializer = new ValueSerializer();
        $value = $this->valueWithPayload(AttributeType::Price, ['amount' => 19.99, 'currency' => 'PLN']);
        self::assertSame('19.99 PLN', $serializer->serialize($value));
    }

    #[Test]
    public function priceFallsBackToPlainValueEnvelope(): void
    {
        // #1271 — a price typed as a bare number on the product card is stored
        // as `{value: "100"}`; it must export instead of an empty cell.
        $serializer = new ValueSerializer();
        $value = $this->valueWithPayload(AttributeType::Price, ['value' => '100']);
        self::assertSame('100', $serializer->serialize($value));
    }

    #[Test]
    public function metricCombinesValueAndUnit(): void
    {
        $serializer = new ValueSerializer();
        $value = $this->valueWithPayload(AttributeType::Metric, ['value' => 12.5, 'unit' => 'kg']);
        self::assertSame('12.5 kg', $serializer->serialize($value));
    }

    #[Test]
    public function assetReturnsAssetId(): void
    {
        // CDN URL minting deferred — emits raw asset_id which pairs with
        // IMP-18 (#604) path-based lookup on reimport.
        $serializer = new ValueSerializer();
        $value = $this->valueWithPayload(AttributeType::Asset, ['asset_id' => '01890abc-0001']);
        self::assertSame('01890abc-0001', $serializer->serialize($value));
    }

    #[Test]
    public function selectReturnsOptionCode(): void
    {
        $serializer = new ValueSerializer();
        $value = $this->valueWithPayload(AttributeType::Select, ['option_code' => 'red']);
        self::assertSame('red', $serializer->serialize($value));
    }

    #[Test]
    public function serializeScalarHandlesNullBoolArrayAndStrings(): void
    {
        $serializer = new ValueSerializer();
        self::assertSame('', $serializer->serializeScalar(null));
        self::assertSame('true', $serializer->serializeScalar(true));
        self::assertSame('false', $serializer->serializeScalar(false));
        self::assertSame('hello', $serializer->serializeScalar('hello'));
        self::assertSame('42', $serializer->serializeScalar(42));
        self::assertSame('a|b|c', $serializer->serializeScalar(['a', 'b', 'c']));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function valueWithPayload(AttributeType $type, array $payload): ObjectValue
    {
        // Lightweight stub — the serializer only touches getAttribute() and
        // getValue(). Use anonymous classes to avoid building a full
        // CatalogObject + Attribute graph for these unit cases.
        return new ObjectValue(
            object: $this->createStub(CatalogObject::class),
            attribute: $this->stubAttribute($type),
            value: $payload,
        );
    }

    private function stubAttribute(AttributeType $type): Attribute
    {
        $stub = $this->createStub(Attribute::class);
        $stub->method('getType')->willReturn($type);

        return $stub;
    }
}
