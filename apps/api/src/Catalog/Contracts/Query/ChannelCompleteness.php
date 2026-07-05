<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Query;

/**
 * DASH-03 (#2253) — read-only per-channel completeness aggregate row.
 * Percentages come from the `per_channel` section of `objects.completeness`
 * (JSONB contract: docs/api/jsonb-schemas.md §3, maintained by
 * AttributesIndexedRebuilder).
 */
final readonly class ChannelCompleteness
{
    public function __construct(
        public string $channelCode,
        /** Channel display name; falls back to the code when the channel row is gone. */
        public string $channelName,
        /** Average per-channel completeness across products, 0-100. */
        public int $avgPct,
        /** Products at/above the publish-ready threshold on this channel. */
        public int $readyCount,
    ) {
    }
}
