<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Validation\TypeValidator;

use App\Catalog\Application\Validation\TypeValidator\NumberValidator;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NumberValidatorTest extends TestCase
{
    private NumberValidator $validator;
    private Attribute $attribute;

    protected function setUp(): void
    {
        $this->validator = new NumberValidator();
        $this->attribute = new Attribute('weight', ['pl' => 'Waga'], AttributeType::Number);
    }

    #[Test]
    public function intAndFloatBothAcceptable(): void
    {
        self::assertSame([], $this->validator->validate($this->attribute, ['value' => 5]));
        self::assertSame([], $this->validator->validate($this->attribute, ['value' => 1.5]));
    }

    #[Test]
    public function nonNumericFails(): void
    {
        $errors = $this->validator->validate($this->attribute, ['value' => '5']);

        self::assertSame('number.expected_numeric', $errors[0]->code);
    }

    #[Test]
    public function minAndMaxBoundsAreEnforced(): void
    {
        $this->attribute->updateValidationRules(['min' => 0, 'max' => 10]);

        $low = $this->validator->validate($this->attribute, ['value' => -1]);
        $high = $this->validator->validate($this->attribute, ['value' => 11]);

        self::assertSame('number.below_min', $low[0]->code);
        self::assertSame('number.above_max', $high[0]->code);
    }

    #[Test]
    public function decimalPrecisionRejectsExtraDigits(): void
    {
        $this->attribute->updateValidationRules(['decimal_precision' => 2]);

        $ok = $this->validator->validate($this->attribute, ['value' => 1.23]);
        $bad = $this->validator->validate($this->attribute, ['value' => 1.234]);

        self::assertSame([], $ok);
        self::assertSame('number.precision_exceeded', $bad[0]->code);
    }
}
