<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Telemetry;

/**
 * 24-hour pull telemetry for the hub KPI + sparkline (XMLF-P3-06).
 *
 * `spark` is exactly 24 hourly buckets ending at the current (partial) hour,
 * oldest first; `pulls24h` is their sum, so the KPI number and the sparkline
 * can never disagree. Bucket granularity is the unit of truth — hits older
 * than the oldest bucket's start are out of the window by definition.
 */
final readonly class FeedPullAggregate
{
    /**
     * @param list<array{bucket: string, count: int}> $spark ATOM bucket start (UTC) → hit count
     */
    public function __construct(
        public int $pulls24h,
        public array $spark,
    ) {
    }
}
