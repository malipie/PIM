<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Query;

use Symfony\Component\Uid\Uuid;

/**
 * AICG-P2-01 (#2331, ADR-0030) — the read-only fact sheet content
 * grounding works from: for each requested attribute code, the most
 * specific value envelope for the (locale, channel) reading per the
 * overlay contract (locale-first chain, #1148), plus sibling-locale
 * readings of the same codes (an existing PL description is a source
 * fact for the EN one — consistency, not re-invention).
 *
 * Envelopes are the `attributes_indexed` value shapes
 * (docs/api/jsonb-schemas.md §1/§6) with the provenance keys stripped —
 * provenance is audit metadata, not a product fact.
 */
final readonly class ObjectFacts
{
    /**
     * @param array<string, array<string, mixed>>                $values         requested code → resolved value envelope
     * @param list<string>                                       $missingCodes   requested codes with no resolvable value
     * @param array<string, array<string, array<string, mixed>>> $siblingLocales requested code → locale → value envelope
     */
    public function __construct(
        public Uuid $objectId,
        public Uuid $objectTypeId,
        public array $values,
        public array $missingCodes,
        public array $siblingLocales = [],
    ) {
    }

    /**
     * @return list<string> codes that resolved to a value — the audit
     *                      trail for provenance_meta.source_attributes
     */
    public function presentCodes(): array
    {
        return array_keys($this->values);
    }
}
