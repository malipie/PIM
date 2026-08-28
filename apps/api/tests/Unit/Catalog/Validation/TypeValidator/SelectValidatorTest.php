<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Validation\TypeValidator;

use App\Catalog\Application\Validation\TypeValidator\SelectValidator;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Repository\AttributeOptionRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SelectValidatorTest extends TestCase
{
    private SelectValidator $validator;
    private Attribute $attribute;

    protected function setUp(): void
    {
        $this->validator = new SelectValidator();
        $this->attribute = new Attribute('color', ['pl' => 'Kolor'], AttributeType::Select);
    }

    #[Test]
    public function optionCodeStringPasses(): void
    {
        self::assertSame([], $this->validator->validate($this->attribute, ['option_code' => 'red']));
    }

    #[Test]
    public function emptyOrMissingOptionCodeFails(): void
    {
        self::assertSame('select.expected_string', $this->validator->validate($this->attribute, [])[0]->code);
        self::assertSame('select.expected_string', $this->validator->validate($this->attribute, ['option_code' => ''])[0]->code);
    }

    #[Test]
    public function unknownOptionRejectedWhenAllowlistConfigured(): void
    {
        $this->attribute->updateValidationRules(['option_codes' => ['red', 'green', 'blue']]);

        $ok = $this->validator->validate($this->attribute, ['option_code' => 'red']);
        $bad = $this->validator->validate($this->attribute, ['option_code' => 'magenta']);

        self::assertSame([], $ok);
        self::assertSame('select.unknown_option', $bad[0]->code);
    }

    #[Test]
    public function liveAttributeOptionsAreTheCanonicalAllowlist(): void
    {
        // #1261 — the DB option set wins over the (absent) validation_rules mirror.
        $repo = $this->createStub(AttributeOptionRepositoryInterface::class);
        $repo->method('findCodesByAttribute')->willReturn(['red', 'green']);
        $validator = new SelectValidator($repo);

        self::assertSame([], $validator->validate($this->attribute, ['option_code' => 'red']));
        $bad = $validator->validate($this->attribute, ['option_code' => 'magenta']);
        self::assertSame('select.unknown_option', $bad[0]->code);
    }

    #[Test]
    public function emptyDbOptionsFallBackToShapeOnly(): void
    {
        // A select with no options yet must not reject every write.
        $repo = $this->createStub(AttributeOptionRepositoryInterface::class);
        $repo->method('findCodesByAttribute')->willReturn([]);
        $validator = new SelectValidator($repo);

        self::assertSame([], $validator->validate($this->attribute, ['option_code' => 'anything']));
    }
}
