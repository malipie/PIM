<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Validation\TypeValidator;

use App\Catalog\Application\Validation\TypeValidator\ColorValidator;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ColorValidatorTest extends TestCase
{
    private ColorValidator $validator;
    private Attribute $attribute;

    protected function setUp(): void
    {
        $this->validator = new ColorValidator();
        $this->attribute = new Attribute('product_color', ['pl' => 'Kolor'], AttributeType::Color);
    }

    #[Test]
    public function validHexPasses(): void
    {
        self::assertSame([], $this->validator->validate($this->attribute, ['value' => '#1a2b3c']));
    }

    #[Test]
    public function emptyPayloadFails(): void
    {
        $errors = $this->validator->validate($this->attribute, []);

        self::assertCount(1, $errors);
        self::assertSame('color.expected_string', $errors[0]->code);
    }

    #[Test]
    public function malformedHexFails(): void
    {
        $errors = $this->validator->validate($this->attribute, ['value' => '123456']);

        self::assertSame('color.invalid_hex', $errors[0]->code);
    }

    #[Test]
    public function shortHexFails(): void
    {
        $errors = $this->validator->validate($this->attribute, ['value' => '#fff']);

        self::assertSame('color.invalid_hex', $errors[0]->code);
    }

    #[Test]
    public function validRgbPassesWhenFormatIsRgb(): void
    {
        $this->attribute->updateValidationRules(['color_format' => 'rgb']);

        self::assertSame([], $this->validator->validate($this->attribute, ['value' => 'rgb(26, 43, 60)']));
    }

    #[Test]
    public function rgbChannelAbove255Fails(): void
    {
        $this->attribute->updateValidationRules(['color_format' => 'rgb']);

        $errors = $this->validator->validate($this->attribute, ['value' => 'rgb(300, 0, 0)']);

        self::assertSame('color.channel_out_of_range', $errors[0]->code);
    }
}
