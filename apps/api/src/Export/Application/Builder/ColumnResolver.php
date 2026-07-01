<?php

declare(strict_types=1);

namespace App\Export\Application\Builder;

/**
 * Parses an `export_session.selected_columns` array into
 * {@see ColumnDefinition}s the builder can consume row by row.
 *
 * Column key syntax (PRD-PIM-exports.md §5.3):
 *   - `sku`, `name`, `parent_sku`, `category`, `created_at`, `updated_at`
 *     → built-in / system kind.
 *   - `<attribute_code>` → bare attribute reference (locale=NULL,
 *     channel=NULL — global value).
 *   - `<attribute_code>.<locale>` → localised value (PRD §6.1, audit
 *     contract 4 — `description.pl`).
 *   - `<attribute_code>.<channel>` → channel-scoped value (PRD §6.1 —
 *     `description.shopify`).
 *   - `<attribute_code>.<locale>.<channel>` → combined locale+channel
 *     value (IMP2-1.6 #1469 — `description.pl.shopify`). Fixed segment
 *     order mirrors the import grammar ({@see \App\Import\Application\Service\ImportColumnGrammar}):
 *     the LAST segment is the channel, the middle one the locale.
 *
 * The parser does NOT verify that the attribute / locale exists — that
 * walidacja lives on the export profile validator (EXP-07) and, for
 * channels, on the preflight (R-47, IMP2-1.6). It only uses the session's
 * channel codes to disambiguate a suffix as channel vs locale. A suffix
 * the resolver cannot interpret as the combined notation degrades to a
 * locale-only column, which the builder turns into a blank cell (R-47).
 */
final class ColumnResolver
{
    /**
     * Built-in column keys mapped to their semantic code.
     *
     * `category` returns a concatenated string of all ObjectCategory rows
     * for the object (delimiter from {@see ValueSerializer::MULTI_VALUE_GLUE}).
     *
     * @var array<string, string>
     */
    private const BUILT_INS = [
        'sku' => 'sku',
        'parent_sku' => 'parent_sku',
        'category' => 'category',
        // XMLF-P3-03 — master category IDS (rfc4122, pipe-joined); the feed
        // generator resolves them to marketplace external codes via the
        // Channel contract. `category` above stays the human-readable codes.
        'category_ids' => 'category_ids',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
        'status' => 'status',
        'enabled' => 'enabled',
        'variant_axes' => 'variant_axes',
        'completeness_pct' => 'completeness_pct',
    ];

    /**
     * @param iterable<string> $selectedColumns
     * @param list<string>     $channelCodes    channel codes active for the session (#1229);
     *                                          a `code.modifier` whose modifier is one of these
     *                                          resolves to a channel-scoped column, otherwise locale
     *
     * @return array<int, ColumnDefinition>
     */
    public function resolve(iterable $selectedColumns, array $channelCodes = []): array
    {
        $resolved = [];
        foreach ($selectedColumns as $key) {
            $resolved[] = $this->resolveOne($key, $channelCodes);
        }

        return $resolved;
    }

    /**
     * @param list<string> $channelCodes
     */
    public function resolveOne(string $key, array $channelCodes = []): ColumnDefinition
    {
        if (\array_key_exists($key, self::BUILT_INS)) {
            return new ColumnDefinition(
                key: $key,
                kind: ColumnDefinition::KIND_BUILT_IN,
                code: self::BUILT_INS[$key],
            );
        }

        $segments = explode('.', $key);
        $code = array_shift($segments);

        if ([] === $segments) {
            return new ColumnDefinition(
                key: $key,
                kind: ColumnDefinition::KIND_ATTRIBUTE,
                code: $code,
            );
        }

        // Single modifier (#1229): a modifier naming one of the session's
        // active channels is a channel-scoped column (`description.shopify`);
        // anything else is a locale (`description.pl`). Disambiguating by
        // membership beats a "short = locale, kebab = channel" heuristic,
        // which would collide on a 2-letter channel code.
        if (1 === \count($segments)) {
            $modifier = $segments[0];

            return \in_array($modifier, $channelCodes, true)
                ? new ColumnDefinition($key, ColumnDefinition::KIND_ATTRIBUTE, $code, channel: $modifier)
                : new ColumnDefinition($key, ColumnDefinition::KIND_ATTRIBUTE, $code, locale: $modifier);
        }

        // Combined notation (IMP2-1.6): `code.locale.channel`. The last
        // segment is the channel; it must name a session channel for this to
        // be the combined form. Anything else degrades to a locale-only
        // column (blank cell, R-47) — never a misread channel.
        $channel = end($segments);
        if (\in_array($channel, $channelCodes, true)) {
            return new ColumnDefinition(
                key: $key,
                kind: ColumnDefinition::KIND_ATTRIBUTE,
                code: $code,
                locale: $segments[0],
                channel: $channel,
            );
        }

        return new ColumnDefinition(
            key: $key,
            kind: ColumnDefinition::KIND_ATTRIBUTE,
            code: $code,
            locale: implode('.', $segments),
        );
    }
}
