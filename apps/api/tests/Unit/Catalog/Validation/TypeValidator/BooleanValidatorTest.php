<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Validation\TypeValidator;

use App\Catalog\Application\Validation\TypeValidator\BooleanValidator;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BooleanValidatorTest extends TestCase
{
    private BooleanValidator $validator;
    private Attribute $attribute;

    protected function setUp(): void
    {
        $this->validator = new BooleanValidator();
        $this->attribute = new Attribute('is_active', ['pl' => 'Aktywny'], AttributeType::Boolean);
    }

    #[Test]
    public function strictTrueAndFalsePass(): void
    {
        self::assertSame([], $this->validator->validate($this->attribute, ['value' => true]));
        self::assertSame([], $this->validator->validate($this->attribute, ['value' => false]));
    }

    #[Test]
    public function truthyButNonBoolValuesAreRejected(): void
    {
        foreach ([1, 0, 'true', 'false', 'on', null] as $bad) {
            $errors = $this->validator->validate($this->attribute, ['value' => $bad]);
            self::assertSame('boolean.expected_bool', $errors[0]->code);
        }
    }
}
