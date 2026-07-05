<?php

declare(strict_types=1);

namespace App\Dashboard\Application\Query;

use App\Catalog\Contracts\Query\ChannelCompleteness;

/**
 * DASH-05 (#2257) — the raw dashboard aggregates, shared by the summary
 * endpoint (composes the JSON response + snapshot deltas on top) and the
 * daily snapshot command (persists them verbatim).
 */
final readonly class DashboardAggregates
{
    /**
     * @param array<int, int>           $cumulativeBuckets threshold => "at least" count
     * @param list<ChannelCompleteness> $channels
     */
    public function __construct(
        public int $productsTotal,
        public int $createdLast30d,
        public int $publishReadyCount,
        public int $avgCompletenessPct,
        public array $cumulativeBuckets,
        public array $channels,
    ) {
    }

    public function publishReadyPct(): int
    {
        return $this->productsTotal > 0
            ? (int) round($this->publishReadyCount / $this->productsTotal * 100)
            : 0;
    }
}
