<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Metrics;

/**
 * Shared state behind Prometheus counters, gauges and histograms.
 *
 * Implementations must keep recording fail-open: losing telemetry must never
 * turn a successful business operation into an application error.
 */
interface MetricsStore
{
    public function incrementCounter(string $metric, string $labelKey): void;

    /**
     * @return array<string, int>
     */
    public function counterSeries(string $metric): array;

    public function setGauge(string $metric, string $labelKey, float $value): void;

    /**
     * @return array<string, float>
     */
    public function gaugeSeries(string $metric): array;

    /**
     * @param list<int> $matchingBucketIndexes cumulative buckets hit by the observation
     */
    public function observeHistogram(string $metric, float $value, array $matchingBucketIndexes): void;

    public function histogram(string $metric, int $bucketCount): HistogramSnapshot;

    public function isAvailable(): bool;
}
