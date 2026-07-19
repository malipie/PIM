<?php

declare(strict_types=1);

namespace App\Catalog\Application\Filter;

use RuntimeException;

use const JSON_THROW_ON_ERROR;

/**
 * #2627 — SQL expression + literal helpers behind {@see FilterDslResolver}'s
 * Postgres path, split out to keep the resolver under the API max-lines
 * guard. Stateless; every method mirrors the resolver's pre-split private
 * helper 1:1.
 *
 * `attributes_indexed` slots are ENVELOPES ({value}, {amount, currency},
 * {option_code}, {option_codes}, {asset_id}, {object_id} — see
 * ValueWriteCore::ALLOWED_KEYS), so left expressions descend into the key
 * matching the attribute type; without a known type the legacy top-level
 * text lookup is kept so behavior degrades predictably.
 */
final class FilterSqlExpressions
{
    /**
     * Envelope-aware JSONB left expression for a non-column attribute.
     */
    public static function leftExpression(string $attr, ?string $type = null): string
    {
        // Locale-scoped path e.g. `description.pl`.
        if (str_contains($attr, '.')) {
            [$base, $locale] = explode('.', $attr, 2);
            $baseEsc = self::safeIdent($base);
            $localeEsc = self::safeIdent($locale);

            return "NULLIF((co.attributes_indexed->'$baseEsc'->>'$localeEsc'), '')";
        }

        $attrEsc = self::safeIdent($attr);

        $valueKey = match ($type) {
            'price' => 'amount',
            'select' => 'option_code',
            'asset' => 'asset_id',
            'relation', 'reference' => 'object_id',
            null => null,
            default => 'value',
        };
        if (null !== $valueKey) {
            return "NULLIF((co.attributes_indexed->'$attrEsc'->>'$valueKey'), '')";
        }

        // Standard JSONB lookup with NULLIF to coerce empty strings to NULL.
        return "NULLIF((co.attributes_indexed->>'$attrEsc'), '')";
    }

    /**
     * Multiselect slots hold `{option_codes: ["a","b"]}` — membership tests
     * ride JSONB containment (`@>`), never the `?` operator (it would be
     * misread as a positional placeholder by consumers embedding this
     * fragment in parametrised queries).
     */
    public static function compileMultiselectCondition(string $attr, string $canonical, mixed $value, string $rawOp): string
    {
        $attrEsc = self::safeIdent($attr);
        $codesText = "NULLIF((co.attributes_indexed->'$attrEsc'->>'option_codes'), '[]')";
        $codesJson = "co.attributes_indexed->'$attrEsc'->'option_codes'";

        switch ($canonical) {
            case FilterDslResolver::OP_IS_EMPTY:
                return $codesText.' IS NULL';

            case FilterDslResolver::OP_IS_NOT_EMPTY:
                return $codesText.' IS NOT NULL';

            case FilterDslResolver::OP_CONTAINS:
                return $codesJson.' @> '.self::jsonbArrayLiteral($value);

            case FilterDslResolver::OP_NOT_CONTAINS:
                // Rows without the attribute count as "does not contain".
                return 'COALESCE(NOT ('.$codesJson.' @> '.self::jsonbArrayLiteral($value).'), true)';

            default:
                throw new RuntimeException('Operator not supported for multiselect: '.$rawOp);
        }
    }

    public static function jsonbArrayLiteral(mixed $value): string
    {
        if (!\is_string($value) || '' === $value) {
            throw new RuntimeException('Multiselect membership requires a non-empty string value.');
        }
        $encoded = json_encode([$value], JSON_THROW_ON_ERROR);

        return "'".str_replace("'", "''", $encoded)."'::jsonb";
    }

    public static function safeIdent(string $ident): string
    {
        // Identifiers come from controlled UI dropdowns; allow safe chars only.
        if (1 !== preg_match('/^[a-zA-Z0-9_\-]+$/', $ident)) {
            throw new RuntimeException('Invalid identifier: '.$ident);
        }

        return $ident;
    }

    public static function literal(mixed $value): string
    {
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_string($value)) {
            return "'".str_replace("'", "''", $value)."'";
        }
        throw new RuntimeException('Unsupported literal type.');
    }

    /**
     * Accept both numeric and date string literals (`2026-05-14`,
     * `2026-05-14T12:00:00Z`). The compiler wraps strings in single
     * quotes for Postgres compatibility.
     */
    public static function scalarLiteral(mixed $value): string
    {
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        if (\is_string($value) && is_numeric($value)) {
            return $value;
        }
        if (\is_string($value) && '' !== $value) {
            return "'".str_replace("'", "''", $value)."'";
        }
        throw new RuntimeException('Numeric or date literal required.');
    }

    public static function likeLiteral(mixed $value, string $prefix, string $suffix): string
    {
        if (!\is_string($value)) {
            throw new RuntimeException('LIKE operator requires a string value.');
        }
        // Escape SQL LIKE wildcards inside the user-provided fragment so a
        // literal `%` is not promoted to a wildcard.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
        $payload = $prefix.$escaped.$suffix;

        return "'".str_replace("'", "''", $payload)."'";
    }

    /**
     * @return array{0: mixed, 1: mixed}
     */
    public static function rangePair(mixed $value): array
    {
        if (!\is_array($value) || 2 !== \count($value)) {
            throw new RuntimeException('BETWEEN operator requires a [low, high] tuple.');
        }
        $list = array_values($value);

        return [$list[0], $list[1]];
    }

    public static function literalList(mixed $value): string
    {
        if (!\is_array($value) || [] === $value) {
            throw new RuntimeException('IN/NOT IN requires a non-empty array value.');
        }

        return implode(', ', array_map(self::literal(...), $value));
    }
}
