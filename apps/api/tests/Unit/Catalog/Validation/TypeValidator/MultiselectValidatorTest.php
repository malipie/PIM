<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Validation\TypeValidator;

use App\Catalog\Application\Validation\TypeValidator\MultiselectValidator;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MultiselectValidatorTest extends TestCase
{
    private MultiselectValidator $validator;
    private Attribute $attribute;

    protected function setUp(): void
    {
        $this->validator = new MultiselectValidator();
        $this->attribute = new Attribute('tags', ['pl' => 'Tagi'], AttributeType::Multiselect);
    }

    #[Test]
    public function listOfStringsPasses(): void
    {
        self::assertSame([], $this->validator->validate($this->attribute, ['option_codes' => ['red', 'blue']]));
    }

    #[Test]
    public function nonArrayFails(): void
    {
        $errors = $this->validator->validate($this->attribute, ['option_codes' => 'red,blue']);

        self::assertSame('multiselect.expected_list', $errors[0]->code);
    }

    #[Test]
    public function nonStringItemsAreReportedPerIndex(): void
    {
        $errors = $this->validator->validate($this->attribute, ['option_codes' => ['ok', 42, '']]);

        self::assertCount(2, $errors);
        self::assertSame('value.option_codes.1', $errors[0]->path);
        self::assertSame('value.option_codes.2', $errors[1]->path);
    }

    #[Test]
    public function allowlistAndCountBoundsCombineErrors(): void
    {
        $this->attribute->updateValidationRules([
            'option_codes' => ['red', 'green'],
            'min_count' => 2,
            'max_count' => 3,
        ]);

        $tooFewWithUnknown = $this->validator->validate($this->attribute, ['option_codes' => ['magenta']]);

        $codes = array_map(static fn ($e) => $e->code, $tooFewWithUnknown);
        self::assertContains('multiselect.unknown_option', $codes);
        self::assertContains('multiselect.too_few', $codes);

        $tooMany = $this->validator->validate($this->attribute, ['option_codes' => ['red', 'green', 'red', 'red']]);
        $codesMany = array_map(static fn ($e) => $e->code, $tooMany);
        self::assertContains('multiselect.too_many', $codesMany);
    }
}
