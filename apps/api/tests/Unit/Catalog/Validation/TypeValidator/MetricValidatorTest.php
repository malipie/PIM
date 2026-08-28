<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Validation\TypeValidator;

use App\Catalog\Application\Validation\TypeValidator\MetricValidator;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MetricValidatorTest extends TestCase
{
    private MetricValidator $validator;
    private Attribute $attribute;

    protected function setUp(): void
    {
        $this->validator = new MetricValidator();
        $this->attribute = new Attribute('weight', ['pl' => 'Waga'], AttributeType::Metric);
    }

    #[Test]
    public function validMetricPasses(): void
    {
        self::assertSame([], $this->validator->validate($this->attribute, ['value' => 12.5, 'unit' => 'kg']));
    }

    #[Test]
    public function nonNumericValueAndEmptyUnitBothFail(): void
    {
        $errors = $this->validator->validate($this->attribute, ['value' => '12', 'unit' => '']);

        $codes = array_map(static fn ($e) => $e->code, $errors);
        self::assertContains('metric.expected_numeric_value', $codes);
        self::assertContains('metric.expected_unit', $codes);
    }

    #[Test]
    public function unitAllowlistIsEnforced(): void
    {
        $this->attribute->updateValidationRules(['units' => ['kg', 'g']]);

        $errors = $this->validator->validate($this->attribute, ['value' => 1, 'unit' => 'lb']);

        self::assertSame('metric.unsupported_unit', $errors[0]->code);
    }

    #[Test]
    public function boundsAndPrecisionEnforced(): void
    {
        $this->attribute->updateValidationRules([
            'min' => 0,
            'max' => 100,
            'decimal_precision' => 1,
        ]);

        $tooLow = $this->validator->validate($this->attribute, ['value' => -1.0, 'unit' => 'kg']);
        $tooHigh = $this->validator->validate($this->attribute, ['value' => 200.0, 'unit' => 'kg']);
        $tooPrecise = $this->validator->validate($this->attribute, ['value' => 1.234, 'unit' => 'kg']);

        self::assertSame('metric.below_min', $tooLow[0]->code);
        self::assertSame('metric.above_max', $tooHigh[0]->code);
        self::assertSame('metric.precision_exceeded', $tooPrecise[0]->code);
    }
}
