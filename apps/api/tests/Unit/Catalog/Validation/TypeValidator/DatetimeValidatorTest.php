<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Validation\TypeValidator;

use App\Catalog\Application\Validation\TypeValidator\DatetimeValidator;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DatetimeValidatorTest extends TestCase
{
    private DatetimeValidator $validator;
    private Attribute $attribute;

    protected function setUp(): void
    {
        $this->validator = new DatetimeValidator();
        $this->attribute = new Attribute('release_at', ['pl' => 'Premiera'], AttributeType::Datetime);
    }

    #[Test]
    public function datetimeLocalStringPasses(): void
    {
        self::assertSame([], $this->validator->validate($this->attribute, ['value' => '2027-03-15T14:30']));
    }

    #[Test]
    public function emptyPayloadFails(): void
    {
        $errors = $this->validator->validate($this->attribute, []);

        self::assertCount(1, $errors);
        self::assertSame('datetime.expected_iso_string', $errors[0]->code);
    }

    #[Test]
    public function unparseableStringFails(): void
    {
        $errors = $this->validator->validate($this->attribute, ['value' => 'not-a-date']);

        self::assertCount(1, $errors);
        self::assertSame('datetime.unparseable', $errors[0]->code);
    }

    #[Test]
    public function belowMinFails(): void
    {
        $this->attribute->updateValidationRules(['min' => '2027-01-01T00:00']);

        $errors = $this->validator->validate($this->attribute, ['value' => '2026-12-31T23:59']);

        self::assertSame('datetime.below_min', $errors[0]->code);
    }

    #[Test]
    public function aboveMaxFails(): void
    {
        $this->attribute->updateValidationRules(['max' => '2027-01-01T00:00']);

        $errors = $this->validator->validate($this->attribute, ['value' => '2027-06-01T12:00']);

        self::assertSame('datetime.above_max', $errors[0]->code);
    }
}
