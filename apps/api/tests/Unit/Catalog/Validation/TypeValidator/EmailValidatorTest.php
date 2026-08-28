<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Validation\TypeValidator;

use App\Catalog\Application\Validation\TypeValidator\EmailValidator;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EmailValidatorTest extends TestCase
{
    private EmailValidator $validator;
    private Attribute $attribute;

    protected function setUp(): void
    {
        $this->validator = new EmailValidator();
        $this->attribute = new Attribute('supplier_email', ['pl' => 'Email dostawcy'], AttributeType::Email);
    }

    #[Test]
    public function validAddressPasses(): void
    {
        self::assertSame([], $this->validator->validate($this->attribute, ['value' => 'contact@example.com']));
    }

    #[Test]
    public function emptyPayloadFails(): void
    {
        $errors = $this->validator->validate($this->attribute, []);

        self::assertCount(1, $errors);
        self::assertSame('email.expected_string', $errors[0]->code);
    }

    #[Test]
    public function malformedAddressFails(): void
    {
        $errors = $this->validator->validate($this->attribute, ['value' => 'not-an-email']);

        self::assertSame('email.invalid', $errors[0]->code);
    }

    #[Test]
    public function patternRuleNarrowsAllowedDomain(): void
    {
        $this->attribute->updateValidationRules(['pattern' => '/@example\.com$/']);

        $ok = $this->validator->validate($this->attribute, ['value' => 'a@example.com']);
        $bad = $this->validator->validate($this->attribute, ['value' => 'a@other.com']);

        self::assertSame([], $ok);
        self::assertSame('email.pattern_mismatch', $bad[0]->code);
    }
}
