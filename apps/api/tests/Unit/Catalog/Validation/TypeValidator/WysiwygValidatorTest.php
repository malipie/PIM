<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Validation\TypeValidator;

use App\Catalog\Application\Validation\TypeValidator\WysiwygValidator;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WysiwygValidatorTest extends TestCase
{
    private WysiwygValidator $validator;
    private Attribute $attribute;

    protected function setUp(): void
    {
        $this->validator = new WysiwygValidator();
        $this->attribute = new Attribute(
            'description_html',
            ['pl' => 'Opis (rich text)', 'en' => 'Description (rich text)'],
            AttributeType::Wysiwyg,
        );
    }

    #[Test]
    public function plainHtmlStringPasses(): void
    {
        self::assertSame([], $this->validator->validate($this->attribute, [
            'value' => '<p>Hello <strong>world</strong></p>',
        ]));
    }

    #[Test]
    public function emptyStringIsAcceptedByDefault(): void
    {
        self::assertSame([], $this->validator->validate($this->attribute, ['value' => '']));
    }

    #[Test]
    public function nonStringValueFails(): void
    {
        $errors = $this->validator->validate($this->attribute, ['value' => ['paragraphs' => []]]);

        self::assertCount(1, $errors);
        self::assertSame('wysiwyg.expected_string', $errors[0]->code);
    }

    #[Test]
    public function maxLengthIsEnforcedInUtf8Characters(): void
    {
        $this->attribute->updateValidationRules(['max_length' => 10]);

        $errors = $this->validator->validate($this->attribute, [
            'value' => '<p>1234567890extra</p>',
        ]);

        self::assertCount(1, $errors);
        self::assertSame('wysiwyg.too_long', $errors[0]->code);
    }
}
