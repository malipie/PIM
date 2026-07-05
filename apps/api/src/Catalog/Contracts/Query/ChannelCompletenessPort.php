<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Query;

/**
 * DASH-03 (#2253) — Contracts seam over the per-channel completeness data
 * (`objects.completeness->'per_channel'`). Read-only aggregate for the
 * dashboard "Kompletność wg kanału" widget; tenant scope comes from
 * explicit tenant predicates inside the implementation.
 */
interface ChannelCompletenessPort
{
    /**
     * Per-channel average completeness + publish-ready counts over
     * product-kind objects, sorted worst-first (lowest average first).
     *
     * @return list<ChannelCompleteness>
     */
    public function perChannel(int $thresholdPct = 80): array;
}
