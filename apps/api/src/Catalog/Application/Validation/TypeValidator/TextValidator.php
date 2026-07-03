<?php

declare(strict_types=1);

namespace App\Catalog\Application\Validation\TypeValidator;

use App\Catalog\Application\Validation\AttributeValueValidatorInterface;
use App\Catalog\Application\Validation\IsoCountryCodes;
use App\Catalog\Application\Validation\ValidationError;
use App\Catalog\Domain\Entity\Attribute;

use const FILTER_VALIDATE_URL;

/**
 * `text` AttributeType validator.
 *
 * Rules from `Attribute.validation_rules`:
 *   - `max_length` (int): UTF-8 character cap
 *   - `min_length` (int): UTF-8 character floor
 *   - `pattern` (string): PCRE regex (must match the whole value)
 *   - `format` (string, DP-06 #2036): `url` (valid absolute URL; add
 *     `require_https: true` to force the https scheme) or `iso_country`
 *     (ISO 3166-1 alpha-2 code, case-insensitive). Unknown format keys
 *     are ignored — graceful degradation, same as IdentifierValidator.
 */
final class TextValidator implements AttributeValueValidatorInterface
{
    public function validate(Attribute $attribute, array $value): array
    {
        $errors = [];
        $raw = $value['value'] ?? null;

        if (!\is_string($raw)) {
            return [new ValidationError('value.value', 'text.expected_string', 'Text value must be a string.')];
        }

        $rules = $attribute->getValidationRules();
        $length = mb_strlen($raw, 'UTF-8');

        $max = $rules['max_length'] ?? null;
        if (\is_int($max) && $length > $max) {
            $errors[] = new ValidationError('value.value', 'text.too_long', \sprintf('Text exceeds max_length=%d (got %d).', $max, $length));
        }
        $min = $rules['min_length'] ?? null;
        if (\is_int($min) && $length < $min) {
            $errors[] = new ValidationError('value.value', 'text.too_short', \sprintf('Text shorter than min_length=%d (got %d).', $min, $length));
        }
        $pattern = $rules['pattern'] ?? null;
        if (\is_string($pattern) && '' !== $pattern && 1 !== preg_match($pattern, $raw)) {
            $errors[] = new ValidationError('value.value', 'text.pattern_mismatch', \sprintf('Text does not match pattern %s.', $pattern));
        }

        $format = $rules['format'] ?? null;
        if ('url' === $format) {
            if (false === filter_var($raw, FILTER_VALIDATE_URL)) {
                $errors[] = new ValidationError('value.value', 'text.invalid_url', \sprintf('"%s" is not a valid URL.', $raw));
            } elseif (true === ($rules['require_https'] ?? null) && !str_starts_with(strtolower($raw), 'https://')) {
                $errors[] = new ValidationError('value.value', 'text.https_required', \sprintf('URL "%s" must use the https scheme.', $raw));
            }
        } elseif ('iso_country' === $format && !IsoCountryCodes::isValid($raw)) {
            $errors[] = new ValidationError('value.value', 'text.invalid_iso_country', \sprintf('"%s" is not an ISO 3166-1 alpha-2 country code.', $raw));
        }

        return $errors;
    }
}
