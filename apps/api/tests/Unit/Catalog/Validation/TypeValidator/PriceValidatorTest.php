<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Validation\TypeValidator;

use App\Catalog\Application\Validation\TypeValidator\PriceValidator;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PriceValidatorTest extends TestCase
{
    private PriceValidator $validator;
    private Attribute $attribute;

    protected function setUp(): void
    {
        $this->validator = new PriceValidator();
        $this->attribute = new Attribute('price', ['pl' => 'Cena'], AttributeType::Price);
    }

    #[Test]
    public function validPricePasses(): void
    {
        self::assertSame([], $this->validator->validate($this->attribute, ['amount' => 19.99, 'currency' => 'PLN']));
    }

    #[Test]
    public function amountWithoutCurrencyPasses(): void
    {
        // #1881 — the product UI submits a bare amount; a price with no
        // currency is valid (currency is optional).
        self::assertSame([], $this->validator->validate($this->attribute, ['amount' => 19.99]));
        self::assertSame([], $this->validator->validate($this->attribute, ['amount' => 19.99, 'currency' => null]));
    }

    #[Test]
    public function nonNumericAmountAndLowercaseCurrencyBothFail(): void
    {
        $errors = $this->validator->validate($this->attribute, ['amount' => '19.99', 'currency' => 'pln']);

        $codes = array_map(static fn ($e) => $e->code, $errors);
        self::assertContains('price.expected_numeric_amount', $codes);
        self::assertContains('price.expected_iso_currency', $codes);
    }

    #[Test]
    public function minAmountAndCurrencyAllowlistEnforced(): void
    {
        $this->attribute->updateValidationRules([
            'min_amount' => 10,
            'currencies' => ['PLN', 'EUR'],
        ]);

        $low = $this->validator->validate($this->attribute, ['amount' => 5, 'currency' => 'PLN']);
        $unsupported = $this->validator->validate($this->attribute, ['amount' => 50, 'currency' => 'USD']);

        self::assertSame('price.below_min', $low[0]->code);
        self::assertSame('price.unsupported_currency', $unsupported[0]->code);
    }

    #[Test]
    public function maxAmountEnforced(): void
    {
        $this->attribute->updateValidationRules(['max_amount' => 100]);

        self::assertSame([], $this->validator->validate($this->attribute, ['amount' => 99.99]));

        $high = $this->validator->validate($this->attribute, ['amount' => 100.01]);
        self::assertSame('price.above_max', $high[0]->code);
    }
}
