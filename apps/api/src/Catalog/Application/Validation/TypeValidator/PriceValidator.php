<?php

declare(strict_types=1);

namespace App\Catalog\Application\Validation\TypeValidator;

use App\Catalog\Application\Validation\AttributeValueValidatorInterface;
use App\Catalog\Application\Validation\ValidationError;
use App\Catalog\Domain\Entity\Attribute;

/**
 * `price` AttributeType validator. Shape: `{amount: numeric, currency?:
 * 'PLN' | 'EUR' | …}`.
 *
 * Currency is OPTIONAL (#1881): the product UI never collected one — the
 * legacy mockup seed (#394) re-introduced a currency requirement that does
 * not exist in the product, so a price is just a numeric amount. When a
 * currency IS present (legacy data / API clients) it is still validated as
 * an ISO 4217 code and against the `currencies` allow-list.
 *
 * Rules:
 *   - `min_amount` (numeric)
 *   - `max_amount` (numeric) — DP-06 (#2036): the jsonb-schemas contract
 *     always declared it, the engine just never read it (docs drift found
 *     while building the DP-05 rules UI)
 *   - `currencies` (list<string>) — restrict the allowed currency set, only
 *     enforced when a currency is supplied
 */
final class PriceValidator implements AttributeValueValidatorInterface
{
    public function validate(Attribute $attribute, array $value): array
    {
        $errors = [];
        $amount = $value['amount'] ?? null;
        $currency = $value['currency'] ?? null;

        if (!\is_int($amount) && !\is_float($amount)) {
            $errors[] = new ValidationError('value.amount', 'price.expected_numeric_amount', 'Price amount must be int or float.');
        }
        // Currency is optional. Validate the format only when one is given —
        // a bare amount (the product UI's only input) is a valid price.
        if (null !== $currency && '' !== $currency
            && (!\is_string($currency) || 1 !== preg_match('/^[A-Z]{3}$/', $currency))) {
            $errors[] = new ValidationError('value.currency', 'price.expected_iso_currency', 'Price currency must be a 3-letter ISO 4217 code (uppercase).');
        }

        $rules = $attribute->getValidationRules();
        $min = $rules['min_amount'] ?? null;
        if ((\is_int($amount) || \is_float($amount)) && (\is_int($min) || \is_float($min)) && $amount < $min) {
            $errors[] = new ValidationError('value.amount', 'price.below_min', \sprintf('Price amount %s below min %s.', (string) $amount, (string) $min));
        }
        $max = $rules['max_amount'] ?? null;
        if ((\is_int($amount) || \is_float($amount)) && (\is_int($max) || \is_float($max)) && $amount > $max) {
            $errors[] = new ValidationError('value.amount', 'price.above_max', \sprintf('Price amount %s above max %s.', (string) $amount, (string) $max));
        }
        $allowed = $rules['currencies'] ?? null;
        if (\is_string($currency) && \is_array($allowed) && !\in_array($currency, $allowed, true)) {
            $errors[] = new ValidationError('value.currency', 'price.unsupported_currency', \sprintf('Currency "%s" is not in the configured currencies set.', $currency));
        }

        return $errors;
    }
}
