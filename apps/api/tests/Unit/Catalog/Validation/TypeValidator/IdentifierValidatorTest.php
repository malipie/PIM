<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Validation\TypeValidator;

use App\Catalog\Application\Validation\TypeValidator\IdentifierValidator;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IdentifierValidatorTest extends TestCase
{
    private IdentifierValidator $validator;
    private Attribute $attribute;

    protected function setUp(): void
    {
        $this->validator = new IdentifierValidator();
        $this->attribute = new Attribute('ean', ['pl' => 'EAN'], AttributeType::Identifier);
    }

    #[Test]
    public function freeFormStringPassesWithoutRules(): void
    {
        self::assertSame([], $this->validator->validate($this->attribute, ['value' => 'SKU-001']));
    }

    #[Test]
    public function emptyPayloadFails(): void
    {
        $errors = $this->validator->validate($this->attribute, []);

        self::assertCount(1, $errors);
        self::assertSame('identifier.expected_string', $errors[0]->code);
    }

    #[Test]
    public function patternRuleIsEnforced(): void
    {
        $this->attribute->updateValidationRules(['pattern' => '/^SKU-\d{3}$/']);

        $ok = $this->validator->validate($this->attribute, ['value' => 'SKU-001']);
        $bad = $this->validator->validate($this->attribute, ['value' => 'XYZ']);

        self::assertSame([], $ok);
        self::assertSame('identifier.pattern_mismatch', $bad[0]->code);
    }

    #[Test]
    public function validEan13ChecksumPasses(): void
    {
        $this->attribute->updateValidationRules(['format' => 'ean13']);

        // 4006381333931 is a textbook valid EAN-13.
        self::assertSame([], $this->validator->validate($this->attribute, ['value' => '4006381333931']));
    }

    #[Test]
    public function invalidEan13ChecksumFails(): void
    {
        $this->attribute->updateValidationRules(['format' => 'ean13']);

        $errors = $this->validator->validate($this->attribute, ['value' => '4006381333930']);

        self::assertSame('identifier.invalid_format', $errors[0]->code);
    }

    #[Test]
    public function validGtin14ChecksumPasses(): void
    {
        $this->attribute->updateValidationRules(['format' => 'gtin14']);

        // 00012345678905 — valid GTIN-14 (mod-10).
        self::assertSame([], $this->validator->validate($this->attribute, ['value' => '00012345678905']));
    }

    #[Test]
    public function validIsbn10ChecksumPasses(): void
    {
        $this->attribute->updateValidationRules(['format' => 'isbn10']);

        // 0306406152 — valid ISBN-10 (mod-11).
        self::assertSame([], $this->validator->validate($this->attribute, ['value' => '0306406152']));
    }

    #[Test]
    public function isbn10WithCheckLetterXPasses(): void
    {
        $this->attribute->updateValidationRules(['format' => 'isbn10']);

        // 097522980X — valid ISBN-10 whose check digit is X (=10).
        self::assertSame([], $this->validator->validate($this->attribute, ['value' => '097522980X']));
    }
}
