<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Validation\TypeValidator;

use App\Catalog\Application\Validation\TypeValidator\DateValidator;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DateValidatorTest extends TestCase
{
    private DateValidator $validator;
    private Attribute $attribute;

    protected function setUp(): void
    {
        $this->validator = new DateValidator();
        $this->attribute = new Attribute('release_date', ['pl' => 'Data premiery'], AttributeType::Date);
    }

    #[Test]
    public function isoDateStringPasses(): void
    {
        self::assertSame([], $this->validator->validate($this->attribute, ['value' => '2026-04-28']));
    }

    #[Test]
    public function emptyOrNonStringFails(): void
    {
        self::assertSame('date.expected_iso_string', $this->validator->validate($this->attribute, ['value' => ''])[0]->code);
        self::assertSame('date.expected_iso_string', $this->validator->validate($this->attribute, ['value' => 20260428])[0]->code);
    }

    #[Test]
    public function unparseableValueFails(): void
    {
        $errors = $this->validator->validate($this->attribute, ['value' => 'not-a-date']);

        self::assertSame('date.unparseable', $errors[0]->code);
    }

    #[Test]
    public function minMaxBoundsAreEnforced(): void
    {
        $this->attribute->updateValidationRules(['min' => '2026-01-01', 'max' => '2026-12-31']);

        $low = $this->validator->validate($this->attribute, ['value' => '2025-12-31']);
        $high = $this->validator->validate($this->attribute, ['value' => '2027-01-01']);
        $ok = $this->validator->validate($this->attribute, ['value' => '2026-06-15']);

        self::assertSame('date.below_min', $low[0]->code);
        self::assertSame('date.above_max', $high[0]->code);
        self::assertSame([], $ok);
    }
}
