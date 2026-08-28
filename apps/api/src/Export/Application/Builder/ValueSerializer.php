<?php

declare(strict_types=1);

namespace App\Export\Application\Builder;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\ObjectValue;
use DateTimeInterface;

/**
 * Converts a stored {@see ObjectValue} JSONB payload into a flat string
 * suitable for an XLSX / CSV cell.
 *
 * Defaults per PRD-PIM-exports.md §8 (round-trip-first contract):
 *   - NULL or missing key → empty string ("blank cell" — §8.1).
 *   - Multiselect / list → pipe-separated tokens (§8.2 default;
 *     reimport side lands with IMP-17 #603).
 *   - Asset → asset_id (CDN URL minting deferred to EXP-08; pairs with
 *     IMP-18 #604 path-based lookup).
 *   - Boolean → "true" / "false" (export-friendly literals; Excel
 *     interprets them as text, NOT booleans — round-trip safe).
 *
 * The class operates on `mixed` JSONB payloads and walks every cell
 * through {@see stringify()} so PHPStan strict's `cannot cast mixed`
 * never surfaces. Any non-scalar that slips through becomes a blank cell
 * rather than corrupting the export.
 */
final class ValueSerializer
{
    public const MULTI_VALUE_GLUE = '|';

    /**
     * Serialise an ObjectValue payload to a single export cell.
     *
     * Returns an empty string when the payload is empty / null — matches
     * PRD §8.1 "blank cell" default. Excel reads it as no value; the
     * round-trip reimport interprets it as "do not change".
     */
    public function serialize(?ObjectValue $value): string
    {
        if (null === $value) {
            return '';
        }

        $payload = $value->getValue();
        if ([] === $payload) {
            return '';
        }

        $type = $value->getAttribute()->getType();

        return match ($type) {
            AttributeType::Text, AttributeType::Wysiwyg => $this->pickValue($payload),
            // #1177 — textarea/color/email all store a scalar string in
            // `value->>'value'`, so they serialise like Text (keeps CSV
            // export readable, which is a primary driver for `textarea`).
            AttributeType::Textarea, AttributeType::Color, AttributeType::Email, AttributeType::Identifier => $this->pickValue($payload),
            AttributeType::Number => $this->pickValue($payload),
            AttributeType::Date, AttributeType::Datetime => $this->pickValue($payload),
            AttributeType::Boolean => $this->boolOf($payload),
            AttributeType::Select => $this->pickKey($payload, 'option_code'),
            AttributeType::Multiselect => $this->multiSelect($payload),
            AttributeType::Price => $this->price($payload),
            AttributeType::Metric => $this->metric($payload),
            AttributeType::Asset => $this->pickKey($payload, 'asset_id'),
            AttributeType::Relation, AttributeType::Reference => $this->pickKey($payload, 'object_id'),
        };
    }

    /**
     * Serialise a raw scalar value (used by built-in columns —
     * sku, name from CatalogObject, parent_sku, dates).
     */
    public function serializeScalar(mixed $value): string
    {
        return $this->stringify($value);
    }

    /**
     * Convert any JSONB payload member to a string export cell.
     *
     * - null → ''
     * - bool → 'true' / 'false' (round-trip friendly, see PRD §8.4)
     * - DateTimeInterface → ISO 8601 (Atom format)
     * - array → pipe-joined per-element stringify (works for gallery
     *   `[url1, url2]` and similar list payloads)
     * - scalar (int/float/string) → cast to string
     * - other (object) → empty string (defensive — JSONB should not hold
     *   objects, but PHPStan strict requires the case)
     */
    private function stringify(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_array($value)) {
            return implode(
                self::MULTI_VALUE_GLUE,
                array_map(fn (mixed $element): string => $this->stringify($element), $value),
            );
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function pickValue(array $payload): string
    {
        return $this->stringify($payload['value'] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function pickKey(array $payload, string $key): string
    {
        return $this->stringify($payload[$key] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function boolOf(array $payload): string
    {
        $v = $payload['value'] ?? null;
        if (null === $v) {
            return '';
        }

        return (bool) $v ? 'true' : 'false';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function multiSelect(array $payload): string
    {
        $codes = $payload['option_codes'] ?? [];
        if (!is_array($codes) || [] === $codes) {
            return '';
        }

        return implode(
            self::MULTI_VALUE_GLUE,
            array_map(fn (mixed $code): string => $this->stringify($code), $codes),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function price(array $payload): string
    {
        // #1271 — the canonical price envelope is `{amount, currency}`, but the
        // product card stores a plain `{value}` when the operator types a bare
        // number into the (text) price field. Fall back to `value` so those
        // prices export instead of rendering an empty cell.
        $amount = $this->stringify($payload['amount'] ?? $payload['value'] ?? null);
        if ('' === $amount) {
            return '';
        }
        $currency = $this->stringify($payload['currency'] ?? null);

        return '' === $currency ? $amount : sprintf('%s %s', $amount, $currency);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function metric(array $payload): string
    {
        $value = $this->stringify($payload['value'] ?? null);
        if ('' === $value) {
            return '';
        }
        $unit = $this->stringify($payload['unit'] ?? null);

        return '' === $unit ? $value : sprintf('%s %s', $value, $unit);
    }
}
