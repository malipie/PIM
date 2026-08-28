<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Validation;

use App\Catalog\Application\Validation\AttributeValueValidator;
use App\Catalog\Application\Validation\AttributeValueValidatorInterface;
use App\Catalog\Application\Validation\ValidationError;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AttributeValueValidatorTest extends TestCase
{
    #[Test]
    public function dispatchesToTheValidatorMatchingAttributeType(): void
    {
        $marker = new class implements AttributeValueValidatorInterface {
            public function validate(Attribute $attribute, array $value): array
            {
                return [new ValidationError('value', 'marker.hit', 'marker reached')];
            }
        };
        $validator = new AttributeValueValidator([
            AttributeType::Text->value => $marker,
        ]);
        $attribute = new Attribute('name', ['pl' => 'Nazwa'], AttributeType::Text);

        $errors = $validator->validate($attribute, ['value' => 'hello']);

        self::assertCount(1, $errors);
        self::assertSame('marker.hit', $errors[0]->code);
    }

    #[Test]
    public function returnsUnsupportedTypeErrorWhenNoValidatorRegistered(): void
    {
        $validator = new AttributeValueValidator([]);
        $attribute = new Attribute('color', ['pl' => 'Kolor'], AttributeType::Select);

        $errors = $validator->validate($attribute, ['option_code' => 'red']);

        self::assertCount(1, $errors);
        self::assertSame('attribute.unsupported_type', $errors[0]->code);
    }

    #[Test]
    public function defaultFactoryRegistersValidatorsForEveryUserFacingAttributeType(): void
    {
        $validator = AttributeValueValidator::default();

        // Smoke: every non-system AttributeType case must dispatch to a real
        // validator, not the unsupported_type fallback. The only system type
        // (`reference` per UI-08.3) is intentionally read-only — it has no
        // validator binding and the dispatcher's fallback is expected
        // behaviour for it. `datetime` became user-facing in #1177.
        foreach (AttributeType::cases() as $type) {
            if ($type->isSystemType()) {
                continue;
            }
            $attribute = new Attribute('a_'.$type->value, ['pl' => $type->value], $type);
            $errors = $validator->validate($attribute, []);

            // All validators reject empty payload with type-specific codes.
            self::assertNotEmpty($errors, \sprintf('Type "%s" produced no errors on empty payload.', $type->value));
            foreach ($errors as $error) {
                self::assertNotSame('attribute.unsupported_type', $error->code, \sprintf('Type "%s" hit the unsupported fallback.', $type->value));
            }
        }
    }

    #[Test]
    public function systemTypesIntentionallyHitUnsupportedFallback(): void
    {
        $validator = AttributeValueValidator::default();

        // `reference` is the only remaining read-only system type (#1177
        // promoted `datetime` to a user-facing, validated type).
        $type = AttributeType::Reference;
        $attribute = new Attribute('sys_'.$type->value, ['en' => 'sys'], $type);
        $errors = $validator->validate($attribute, []);

        self::assertNotEmpty($errors);
        self::assertSame('attribute.unsupported_type', $errors[0]->code);
    }
}
