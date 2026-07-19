<?php

declare(strict_types=1);

namespace App\Integration\Generic\Infrastructure\Http;

use App\Integration\Generic\Application\Sync\RemotePathSegments;

/**
 * Resolves a record selector against a decoded JSON body (ADR-0022, epic APIC,
 * ticket APIC-P2-03).
 *
 * MVP supports the dot-path + bracket subset of JSONPath the wizard emits —
 * `$` (root), `$.results`, `$.data.items`, `$.prices['1374']`, or the bare
 * `results` form (#2642). Full JSONPath (filters, wildcards, recursive
 * descent) is a deferred §7 hook; the selector column is wide enough to grow
 * into it without a migration.
 *
 * `records()` always yields a list of associative records: a list selector
 * returns its rows, a single object is wrapped, anything else is empty.
 * `value()` returns the raw node for scalar lookups (e.g. the next cursor).
 */
final class RecordSelector
{
    /**
     * Extracts the list of records a read endpoint returns. Keys are
     * `array-key` rather than `string` — JSON object keys decode to strings,
     * but that cannot be proven from a `mixed` body, so the safe superset is
     * declared and downstream lookups use the (string) field path regardless.
     *
     * @param mixed $decoded the json_decode(..., true) body
     *
     * @return list<array<array-key, mixed>>
     */
    public function records(mixed $decoded, ?string $selector): array
    {
        $node = $this->value($decoded, $selector);

        if (!\is_array($node) || [] === $node) {
            return [];
        }

        if (array_is_list($node)) {
            return self::arrayRows($node);
        }

        // A single object response (read_one, or a list endpoint returning one record).
        return [$node];
    }

    /**
     * Keeps only the array elements of a list (skips scalar rows).
     *
     * @param list<mixed> $rows
     *
     * @return list<array<array-key, mixed>>
     */
    private static function arrayRows(array $rows): array
    {
        $records = [];
        foreach ($rows as $row) {
            if (\is_array($row)) {
                $records[] = $row;
            }
        }

        return $records;
    }

    /**
     * Resolves a dot path to its raw node, or null when any segment is missing.
     *
     * @param mixed $decoded the json_decode(..., true) body
     */
    public function value(mixed $decoded, ?string $path): mixed
    {
        $segments = self::segments($path);
        $node = $decoded;

        foreach ($segments as $segment) {
            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * Splits `$.a.b` / `a.b['c']` / `$` into path segments (#2642 — shared
     * tokenizer with bracket-notation support). Root (`$`, ``, null) yields no
     * segments, so the whole body is returned.
     *
     * @return list<string>
     */
    private static function segments(?string $path): array
    {
        return RemotePathSegments::parse($path);
    }
}
