<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Validation\TypeValidator;

use App\Catalog\Application\Validation\TypeValidator\TextareaValidator;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextareaValidatorTest extends TestCase
{
    private TextareaValidator $validator;
    private Attribute $attribute;

    protected function setUp(): void
    {
        $this->validator = new TextareaValidator();
        $this->attribute = new Attribute('short_desc', ['pl' => 'Krótki opis'], AttributeType::Textarea);
    }

    #[Test]
    public function multiLineStringPasses(): void
    {
        self::assertSame([], $this->validator->validate($this->attribute, ['value' => "line 1\nline 2"]));
    }

    #[Test]
    public function nonStringValueFails(): void
    {
        $errors = $this->validator->validate($this->attribute, ['value' => 42]);

        self::assertCount(1, $errors);
        self::assertSame('textarea.expected_string', $errors[0]->code);
    }

    #[Test]
    public function emptyPayloadFails(): void
    {
        $errors = $this->validator->validate($this->attribute, []);

        self::assertCount(1, $errors);
        self::assertSame('textarea.expected_string', $errors[0]->code);
    }

    #[Test]
    public function maxLengthIsEnforcedInUtf8Characters(): void
    {
        $this->attribute->updateValidationRules(['max_length' => 3]);

        $errors = $this->validator->validate($this->attribute, ['value' => 'łóść']);

        self::assertCount(1, $errors);
        self::assertSame('textarea.too_long', $errors[0]->code);
    }

    #[Test]
    public function minLengthIsEnforced(): void
    {
        $this->attribute->updateValidationRules(['min_length' => 5]);

        $errors = $this->validator->validate($this->attribute, ['value' => 'hi']);

        self::assertSame('textarea.too_short', $errors[0]->code);
    }
}
