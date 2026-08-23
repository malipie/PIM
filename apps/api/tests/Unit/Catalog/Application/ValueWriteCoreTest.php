<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\ValueWriteCore;
use App\Catalog\Domain\AttributeType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * IMP2-1.2 (#1464) follow-up — pins the ADR-0019 / D7 per-type JSONB canon
 * produced by ValueWriteCore::normalise(). The audit flagged this as a closed
 * ticket whose required wrapValue/normalise unit tests were never written even
 * though the code works end-to-end. normalise() routes through canonicalise()
 * and uses no collaborators, so this is a pure unit test.
 */
final class ValueWriteCoreTest extends TestCase
{
    /**
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('canonProvider')]
    public function normaliseProducesPerTypeCanon(AttributeType $type, mixed $raw, array $expected): void
    {
        // normalise() routes through the private canonicalise() and reads no
        // collaborators, so we skip the (final, DI-heavy) constructor.
        $core = new ReflectionClass(ValueWriteCore::class)->newInstanceWithoutConstructor();

        self::assertSame($expected, $core->normalise($type, $raw));
    }

    /**
     * @return iterable<string, array{AttributeType, mixed, array<string, mixed>}>
     */
    public static function canonProvider(): iterable
    {
        // Scalar types → {value: <scalar>}
        yield 'text' => [AttributeType::Text, 'hello', ['value' => 'hello']];
        yield 'textarea' => [AttributeType::Textarea, 'long', ['value' => 'long']];
        yield 'number' => [AttributeType::Number, 42, ['value' => 42]];
        yield 'boolean false stays a value' => [AttributeType::Boolean, false, ['value' => false]];
        yield 'identifier' => [AttributeType::Identifier, 'EAN-1', ['value' => 'EAN-1']];
        yield 'email' => [AttributeType::Email, 'a@b.pl', ['value' => 'a@b.pl']];
        yield 'date' => [AttributeType::Date, '2026-06-16', ['value' => '2026-06-16']];

        // Select → {option_code}
        yield 'select bare code' => [AttributeType::Select, 'red', ['option_code' => 'red']];
        yield 'select legacy {value} wrap' => [AttributeType::Select, ['value' => 'red'], ['option_code' => 'red']];
        yield 'select already canonical' => [AttributeType::Select, ['option_code' => 'red'], ['option_code' => 'red']];

        // Multiselect → {option_codes}
        yield 'multiselect bare list' => [AttributeType::Multiselect, ['a', 'b'], ['option_codes' => ['a', 'b']]];
        yield 'multiselect {value: list}' => [AttributeType::Multiselect, ['value' => ['a', 'b']], ['option_codes' => ['a', 'b']]];

        // Price → {amount}
        yield 'price float' => [AttributeType::Price, 99.99, ['amount' => 99.99]];
        yield 'price numeric string' => [AttributeType::Price, '99.99', ['amount' => 99.99]];
        yield 'price canonical kept' => [AttributeType::Price, ['amount' => 99.99, 'currency' => 'PLN'], ['amount' => 99.99, 'currency' => 'PLN']];

        // Object-shaped types pass through unchanged (the admin FE sends them
        // already canonical; canonicalise() only rewrites legacy {value} wraps).
        yield 'metric object' => [AttributeType::Metric, ['value' => 5, 'unit' => 'kg'], ['value' => 5, 'unit' => 'kg']];
        yield 'asset object' => [AttributeType::Asset, ['asset_id' => 'a1'], ['asset_id' => 'a1']];
        yield 'asset bare id' => [AttributeType::Asset, 'a1', ['asset_id' => 'a1']];
        yield 'relation object' => [AttributeType::Relation, ['object_id' => 'o1'], ['object_id' => 'o1']];
        yield 'relation bare id' => [AttributeType::Relation, 'o1', ['object_id' => 'o1']];
        yield 'reference object' => [AttributeType::Reference, ['object_id' => 'o1'], ['object_id' => 'o1']];
    }
}
