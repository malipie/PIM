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
     * Envelope key for a given attribute type — shared by the global
     * (`attributes_indexed`) and scoped (`object_values`) left expressions.
     */
    public static function envelopeKey(?string $type): ?string
    {
        return match ($type) {
            'price' => 'amount',
            'select' => 'option_code',
            'asset' => 'asset_id',
            'relation', 'reference' => 'object_id',
            null => null,
            default => 'value',
        };
    }

    /**
     * #2673 — value-context left expression: the effective value of `$attr`
     * for the (channel, locale) scope with fallback to the global slot.
     *
     * The correlated subselect probes `object_values` for the scope-specific
     * slots only (the global slot rides the COALESCE fallback through the
     * legacy `attributes_indexed` expression, envelope quirks included).
     * With both dimensions set the candidates are (c,l) > (c,∅) > (∅,l),
     * ordered by specificity; with a single dimension the unique constraint
     * guarantees at most one row. Covered by `object_values_scope_uniq`
     * (leading object_id, attribute_id) — no extra index needed.
     *
     * Note the NULLIF('') coercion: a scoped slot holding an empty string
     * falls back to the global value, mirroring the global path — so
     * `IS [NOT] EMPTY` reads as "the EFFECTIVE value in this scope".
     */
    public static function scopedLeftExpression(
        string $attr,
        ?string $type,
        string $attributeId,
        ?string $channelId,
        ?string $locale,
    ): string {
        $key = self::envelopeKey($type) ?? 'value';
        $inner = "NULLIF((ov.value->>'".self::safeIdent($key)."'), '')";

        $subselect = self::scopedSubselect($inner, $attributeId, $channelId, $locale);

        return 'COALESCE('.$subselect.', '.self::leftExpression($attr, $type).')';
    }

    /**
     * Correlated scoped probe returning `$innerExpression` evaluated on the
     * best-matching scope-specific `object_values` row (never the global one).
     */
    public static function scopedSubselect(
        string $innerExpression,
        string $attributeId,
        ?string $channelId,
        ?string $locale,
    ): string {
        if (null === $channelId && null === $locale) {
            throw new RuntimeException('Scoped subselect requires a channel or a locale.');
        }
        $attrId = self::safeUuid($attributeId);

        $predicates = [
            'ov.object_id = co.id',
            'ov.tenant_id = co.tenant_id',
            "ov.attribute_id = '$attrId'",
        ];
        $order = '';
        if (null !== $channelId && null !== $locale) {
            $ch = self::safeUuid($channelId);
            $loc = self::safeIdent($locale);
            $predicates[] = "(ov.channel_id = '$ch' OR ov.channel_id IS NULL)";
            $predicates[] = "(ov.locale = '$loc' OR ov.locale IS NULL)";
            $predicates[] = 'NOT (ov.channel_id IS NULL AND ov.locale IS NULL)';
            $order = ' ORDER BY (ov.channel_id IS NOT NULL) DESC, (ov.locale IS NOT NULL) DESC';
        } elseif (null !== $channelId) {
            $ch = self::safeUuid($channelId);
            $predicates[] = "ov.channel_id = '$ch'";
            $predicates[] = 'ov.locale IS NULL';
        } else {
            /** @var string $locale narrowed by the guard above */
            $loc = self::safeIdent($locale);
            $predicates[] = 'ov.channel_id IS NULL';
            $predicates[] = "ov.locale = '$loc'";
        }

        return '(SELECT '.$innerExpression.' FROM object_values ov WHERE '
            .implode(' AND ', $predicates).$order.' LIMIT 1)';
    }

    public static function safeUuid(string $uuid): string
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
            throw new RuntimeException('Invalid UUID literal: '.$uuid);
        }

        return $uuid;
    }

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

        $valueKey = self::envelopeKey($type);
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
    public static function compileMultiselectCondition(
        string $attr,
        string $canonical,
        mixed $value,
        string $rawOp,
        ?string $scopedAttributeId = null,
        ?string $scopedChannelId = null,
        ?string $scopedLocale = null,
    ): string {
        $attrEsc = self::safeIdent($attr);
        $codesText = "NULLIF((co.attributes_indexed->'$attrEsc'->>'option_codes'), '[]')";
        $codesJson = "co.attributes_indexed->'$attrEsc'->'option_codes'";

        // #2673 — value-context: prefer the scope-specific slot, fall back to
        // the global one (an empty scoped list falls back too, mirroring the
        // scalar NULLIF('') coercion).
        if (null !== $scopedAttributeId && (null !== $scopedChannelId || null !== $scopedLocale)) {
            $scopedText = self::scopedSubselect(
                "NULLIF((ov.value->>'option_codes'), '[]')",
                $scopedAttributeId,
                $scopedChannelId,
                $scopedLocale,
            );
            $scopedJson = self::scopedSubselect(
                "NULLIF((ov.value->'option_codes'), '[]'::jsonb)",
                $scopedAttributeId,
                $scopedChannelId,
                $scopedLocale,
            );
            $codesText = 'COALESCE('.$scopedText.', '.$codesText.')';
            $codesJson = 'COALESCE('.$scopedJson.', '.$codesJson.')';
        }

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
