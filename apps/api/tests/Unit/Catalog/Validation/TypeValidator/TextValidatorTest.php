<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Validation\TypeValidator;

use App\Catalog\Application\Validation\TypeValidator\TextValidator;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextValidatorTest extends TestCase
{
    private TextValidator $validator;
    private Attribute $attribute;

    protected function setUp(): void
    {
        $this->validator = new TextValidator();
        $this->attribute = new Attribute('name', ['pl' => 'Nazwa'], AttributeType::Text);
    }

    #[Test]
    public function plainStringPasses(): void
    {
        self::assertSame([], $this->validator->validate($this->attribute, ['value' => 'hello']));
    }

    #[Test]
    public function nonStringValueFails(): void
    {
        $errors = $this->validator->validate($this->attribute, ['value' => 42]);

        self::assertCount(1, $errors);
        self::assertSame('text.expected_string', $errors[0]->code);
    }

    #[Test]
    public function maxLengthIsEnforcedInUtf8Characters(): void
    {
        $this->attribute->updateValidationRules(['max_length' => 3]);

        // 4 utf-8 characters (with polish diacritics).
        $errors = $this->validator->validate($this->attribute, ['value' => 'łóść']);

        self::assertCount(1, $errors);
        self::assertSame('text.too_long', $errors[0]->code);
    }

    #[Test]
    public function minLengthIsEnforced(): void
    {
        $this->attribute->updateValidationRules(['min_length' => 5]);

        $errors = $this->validator->validate($this->attribute, ['value' => 'hi']);

        self::assertSame('text.too_short', $errors[0]->code);
    }

    #[Test]
    public function patternMustMatchTheWholeValue(): void
    {
        $this->attribute->updateValidationRules(['pattern' => '/^[A-Z]{3}$/']);

        $ok = $this->validator->validate($this->attribute, ['value' => 'ABC']);
        $bad = $this->validator->validate($this->attribute, ['value' => 'abc']);

        self::assertSame([], $ok);
        self::assertSame('text.pattern_mismatch', $bad[0]->code);
    }

    #[Test]
    public function urlFormatAcceptsValidUrlsAndRejectsGarbage(): void
    {
        $this->attribute->updateValidationRules(['format' => 'url']);

        self::assertSame(
            [],
            $this->validator->validate($this->attribute, ['value' => 'http://example.com/path?q=1']),
        );

        $bad = $this->validator->validate($this->attribute, ['value' => 'not a url']);
        self::assertSame('text.invalid_url', $bad[0]->code);
    }

    #[Test]
    public function requireHttpsRejectsPlainHttp(): void
    {
        $this->attribute->updateValidationRules(['format' => 'url', 'require_https' => true]);

        self::assertSame(
            [],
            $this->validator->validate($this->attribute, ['value' => 'https://example.com/a.pdf']),
        );

        $bad = $this->validator->validate($this->attribute, ['value' => 'http://example.com/a.pdf']);
        self::assertSame('text.https_required', $bad[0]->code);
    }

    #[Test]
    public function isoCountryFormatValidatesAlpha2CaseInsensitively(): void
    {
        $this->attribute->updateValidationRules(['format' => 'iso_country']);

        self::assertSame([], $this->validator->validate($this->attribute, ['value' => 'PL']));
        self::assertSame([], $this->validator->validate($this->attribute, ['value' => 'de']));

        $bad = $this->validator->validate($this->attribute, ['value' => 'XX']);
        self::assertSame('text.invalid_iso_country', $bad[0]->code);

        $word = $this->validator->validate($this->attribute, ['value' => 'Polska']);
        self::assertSame('text.invalid_iso_country', $word[0]->code);
    }

    #[Test]
    public function unknownFormatKeyIsIgnored(): void
    {
        $this->attribute->updateValidationRules(['format' => 'some_future_format']);

        self::assertSame([], $this->validator->validate($this->attribute, ['value' => 'anything']));
    }
}
