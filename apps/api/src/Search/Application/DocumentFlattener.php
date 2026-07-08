<?php

declare(strict_types=1);

namespace App\Search\Application;

/**
 * Promotes scalar values out of `attributes_indexed` envelopes into
 * top-level Meilisearch document keys so they can be referenced by
 * `filterableAttributes` (e.g. `brand`, `family`, `color`) and faceted
 * without nested-path gymnastics.
 *
 * The envelopes follow `docs/api/jsonb-schemas.md`: text/number/date use
 * `{value}`, single-select uses `{option_code}`, multi-select uses
 * `{option_codes}`, price uses `{amount, currency}`. The flattener picks
 * the natural filterable scalar per shape and emits it next to the
 * envelope. Keys colliding with reserved doc fields (`id`, `code`,
 * `kind`, `tenantId`, `status`, `enabled`, `parentId`, `path`,
 * `attributesIndexed`, `completeness`, `completeness_pct`, `createdAt`,
 * `updatedAt`) are dropped to avoid overwriting indexer-owned metadata.
 */
final class DocumentFlattener
{
    private const array RESERVED_KEYS = [
        'id', 'code', 'kind', 'tenantId', 'objectTypeId', 'status', 'enabled',
        'parentId', 'path', 'attributesIndexed', 'completeness', 'completeness_pct',
        'createdAt', 'updatedAt',
    ];

    /**
     * @param array<string, mixed> $attributesIndexed
     *
     * @return array<string, mixed>
     */
    public static function flatten(array $attributesIndexed): array
    {
        $out = [];
        foreach ($attributesIndexed as $code => $envelope) {
            if (\in_array($code, self::RESERVED_KEYS, true)) {
                continue;
            }
            // Bulk handlers (VIEW-12 path) write raw scalars to
            // `attributes_indexed` instead of the canonical
            // `{value: X}` envelope used by ObjectAttributesUpserter.
            // Both shapes must funnel into the same Meili top-level so
            // a brand filter set via the wizard returns hits.
            if (\is_scalar($envelope) || null === $envelope) {
                $out[$code] = $envelope;
                continue;
            }
            if (!\is_array($envelope)) {
                continue;
            }
            if (\array_key_exists('value', $envelope)) {
                $v = $envelope['value'];
                if (\is_scalar($v) || null === $v) {
                    $out[$code] = $v;
                }
                continue;
            }
            if (\array_key_exists('option_code', $envelope) && \is_scalar($envelope['option_code'])) {
                $out[$code] = $envelope['option_code'];
                continue;
            }
            if (\array_key_exists('option_codes', $envelope) && \is_array($envelope['option_codes'])) {
                $out[$code] = array_values(array_filter($envelope['option_codes'], 'is_scalar'));
                continue;
            }
            if (\array_key_exists('amount', $envelope) && \is_numeric($envelope['amount'])) {
                // Price envelope `{amount, currency}` — the amount drives
                // most range/filter queries; the currency comes along in
                // the original envelope for FE rendering.
                $out[$code] = (float) $envelope['amount'];
            }
        }

        return $out;
    }
}
