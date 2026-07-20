<?php

declare(strict_types=1);

namespace App\Catalog\Application\Filter;

use App\Shared\Infrastructure\Meilisearch\MeiliFilterLiteral;
use RuntimeException;

/**
 * #2673 — Meilisearch condition compiler behind
 * {@see FilterDslResolver::toMeilisearchFilter()}, split out (like the
 * #2627 SQL twin {@see FilterSqlExpressions}) to keep the resolver under
 * the API max-lines guard. Stateless; every method mirrors the resolver's
 * pre-split private helper 1:1.
 */
final class FilterMeiliExpressions
{
    /**
     * @param array<string, mixed> $cond
     */
    public static function compileCondition(array $cond): string
    {
        $attrRaw = $cond['attr'] ?? null;
        $opRaw = $cond['op'] ?? null;
        if (!\is_string($attrRaw) || !\is_string($opRaw)) {
            throw new RuntimeException('Condition attr/op must be strings.');
        }
        $attr = self::attrPath($attrRaw);
        $canonical = FilterDslResolver::normaliseOperator($opRaw);
        $value = $cond['value'] ?? null;

        switch ($canonical) {
            case FilterDslResolver::OP_IS_EMPTY:
                return "(NOT $attr EXISTS OR $attr IS NULL OR $attr IS EMPTY)";

            case FilterDslResolver::OP_IS_NOT_EMPTY:
                return "($attr EXISTS AND $attr IS NOT NULL AND $attr IS NOT EMPTY)";

            case FilterDslResolver::OP_EQ:
                return "$attr = ".self::literal($value);

            case FilterDslResolver::OP_NEQ:
                return "$attr != ".self::literal($value);

            case FilterDslResolver::OP_LT:
            case FilterDslResolver::OP_BEFORE:
                return "$attr < ".self::scalar($value);

            case FilterDslResolver::OP_GT:
            case FilterDslResolver::OP_AFTER:
                return "$attr > ".self::scalar($value);

            case FilterDslResolver::OP_LTE:
                return "$attr <= ".self::scalar($value);

            case FilterDslResolver::OP_GTE:
                return "$attr >= ".self::scalar($value);

            case FilterDslResolver::OP_IN:
                return "$attr IN [".self::literalList($value).']';

            case FilterDslResolver::OP_NOT_IN:
                return "$attr NOT IN [".self::literalList($value).']';

            case FilterDslResolver::OP_STARTS_WITH:
                return "$attr STARTS WITH ".self::literal($value);

            case FilterDslResolver::OP_ENDS_WITH:
                // Meilisearch lacks ENDS WITH — emulate via CONTAINS
                // (full-text); behaviour drifts vs SQL but covers the
                // common case of suffix lookup in admin search.
                return "$attr CONTAINS ".self::literal($value);

            case FilterDslResolver::OP_CONTAINS:
                return "$attr CONTAINS ".self::literal($value);

            case FilterDslResolver::OP_NOT_CONTAINS:
                return "NOT ($attr CONTAINS ".self::literal($value).')';

            case FilterDslResolver::OP_BETWEEN:
                [$lo, $hi] = FilterSqlExpressions::rangePair($value);

                return "$attr ".self::scalar($lo).' TO '.self::scalar($hi);

            case FilterDslResolver::OP_IS_TRUE:
                return "$attr = true";

            case FilterDslResolver::OP_IS_FALSE:
                return "$attr = false";

            default:
                throw new RuntimeException('Operator not supported in Meilisearch compiler: '.$opRaw);
        }
    }

    public static function attrPath(string $attr): string
    {
        // #2237 — `sku` is the UI/agent alias for the natural key; the
        // Meili document stores it as `code` (the physical column, mirroring
        // COLUMN_MAP `sku => co.code` on the SQL path). Without this the
        // agent's grounding filter `sku = "…"` hit a non-existent Meili field
        // and the search degraded — misread as a backend outage.
        if ('sku' === $attr) {
            return 'code';
        }

        if (str_contains($attr, '.')) {
            [$base, $locale] = explode('.', $attr, 2);
            FilterSqlExpressions::safeIdent($base);
            FilterSqlExpressions::safeIdent($locale);

            return $base.'.'.$locale;
        }

        return FilterSqlExpressions::safeIdent($attr);
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
            return MeiliFilterLiteral::quote($value);
        }
        throw new RuntimeException('Unsupported Meilisearch literal type.');
    }

    public static function scalar(mixed $value): string
    {
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        if (\is_string($value) && is_numeric($value)) {
            return $value;
        }
        if (\is_string($value)) {
            return self::literal($value);
        }
        throw new RuntimeException('Numeric or date literal required.');
    }

    public static function literalList(mixed $value): string
    {
        if (!\is_array($value) || [] === $value) {
            throw new RuntimeException('IN/NOT IN requires a non-empty array value.');
        }

        return implode(', ', array_map(self::literal(...), $value));
    }
}
