<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Contracts\AttributeType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AttributeTypeTest extends TestCase
{
    #[Test]
    public function legacyDomainNameResolvesToTheContractEnum(): void
    {
        self::assertTrue(class_exists('App\\Catalog\\Domain\\AttributeType'));
        self::assertSame(AttributeType::Text, constant('App\\Catalog\\Domain\\AttributeType::Text'));
    }

    #[Test]
    public function casesAreDefinedExactly(): void
    {
        // 10 user-facing types from ADR-006 + `wysiwyg` (VIEW-07.2 #423)
        // + `datetime`/`reference` (UI-08.3 #258) + `textarea`/`color`/`email`
        // (#1177). `datetime` is now user-facing (#1177); only `reference`
        // stays a system type. Guard against accidental case removal/addition.
        self::assertCount(17, AttributeType::cases());
        self::assertCount(1, array_filter(AttributeType::cases(), static fn (AttributeType $t) => $t->isSystemType()));
        self::assertTrue(AttributeType::Reference->isSystemType());
        self::assertFalse(AttributeType::Datetime->isSystemType());
    }

    #[Test]
    public function backingValuesAreLowercaseStrings(): void
    {
        // The DB stores strings; round-trip via `from()` must work and
        // values must be grep-friendly in fixtures + migrations.
        foreach (AttributeType::cases() as $case) {
            self::assertSame(strtolower($case->value), $case->value, $case->name);
            self::assertSame($case, AttributeType::from($case->value));
        }
    }

    /**
     * @return iterable<string, array{AttributeType, bool}>
     */
    public static function optionUsage(): iterable
    {
        yield 'text does not use options' => [AttributeType::Text, false];
        yield 'number does not use options' => [AttributeType::Number, false];
        yield 'select uses options' => [AttributeType::Select, true];
        yield 'multiselect uses options' => [AttributeType::Multiselect, true];
        yield 'date does not use options' => [AttributeType::Date, false];
        yield 'boolean does not use options' => [AttributeType::Boolean, false];
        yield 'asset does not use options' => [AttributeType::Asset, false];
        yield 'relation does not use options' => [AttributeType::Relation, false];
        yield 'price does not use options' => [AttributeType::Price, false];
        yield 'metric does not use options' => [AttributeType::Metric, false];
        yield 'datetime does not use options' => [AttributeType::Datetime, false];
        yield 'reference does not use options' => [AttributeType::Reference, false];
        yield 'textarea does not use options' => [AttributeType::Textarea, false];
        yield 'color does not use options' => [AttributeType::Color, false];
        yield 'email does not use options' => [AttributeType::Email, false];
        yield 'identifier does not use options' => [AttributeType::Identifier, false];
    }

    #[Test]
    #[DataProvider('optionUsage')]
    public function usesOptionsReportsTrueOnlyForSelectVariants(AttributeType $type, bool $expected): void
    {
        self::assertSame($expected, $type->usesOptions());
    }
}
