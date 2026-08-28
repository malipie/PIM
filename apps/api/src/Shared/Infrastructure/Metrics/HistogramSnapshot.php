<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Metrics;

/**
 * Immutable, internally consistent histogram state returned by a metrics store.
 *
 * @param list<int> $bucketCounts cumulative counts in the caller's bucket order
 */
final readonly class HistogramSnapshot
{
    /**
     * @param list<int> $bucketCounts
     */
    public function __construct(
        public int $count,
        public float $sum,
        public array $bucketCounts,
    ) {
    }
}
