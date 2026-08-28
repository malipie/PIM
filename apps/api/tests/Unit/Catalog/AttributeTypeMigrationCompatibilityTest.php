<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Service\AttributeTypeMigrationCompatibility;
use App\Catalog\Domain\Service\MigrationCompatibility;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AttributeTypeMigrationCompatibilityTest extends TestCase
{
    #[Test]
    public function sameTypeIsAlwaysSafe(): void
    {
        $matrix = new AttributeTypeMigrationCompatibility();

        foreach (AttributeType::cases() as $type) {
            self::assertSame(
                MigrationCompatibility::Safe,
                $matrix->evaluate($type, $type),
                $type->value,
            );
        }
    }

    #[Test]
    public function textToSelectIsSafe(): void
    {
        $matrix = new AttributeTypeMigrationCompatibility();
        self::assertSame(MigrationCompatibility::Safe, $matrix->evaluate(AttributeType::Text, AttributeType::Select));
    }

    #[Test]
    public function multiselectToSelectRequiresForce(): void
    {
        $matrix = new AttributeTypeMigrationCompatibility();
        self::assertSame(
            MigrationCompatibility::RequiresForce,
            $matrix->evaluate(AttributeType::Multiselect, AttributeType::Select),
        );
    }

    #[Test]
    public function textToBooleanRequiresForce(): void
    {
        $matrix = new AttributeTypeMigrationCompatibility();
        self::assertSame(
            MigrationCompatibility::RequiresForce,
            $matrix->evaluate(AttributeType::Text, AttributeType::Boolean),
        );
    }

    #[Test]
    public function assetToNumberIsBlocked(): void
    {
        $matrix = new AttributeTypeMigrationCompatibility();
        self::assertSame(
            MigrationCompatibility::Blocked,
            $matrix->evaluate(AttributeType::Asset, AttributeType::Number),
        );
    }

    #[Test]
    public function systemTypesAreBlockedAsTargets(): void
    {
        $matrix = new AttributeTypeMigrationCompatibility();
        self::assertSame(
            MigrationCompatibility::Blocked,
            $matrix->evaluate(AttributeType::Text, AttributeType::Datetime),
        );
        self::assertSame(
            MigrationCompatibility::Blocked,
            $matrix->evaluate(AttributeType::Text, AttributeType::Reference),
        );
    }
}
