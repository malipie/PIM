<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Metrics;

/**
 * Dependency-free store for isolated unit tests that construct registries
 * directly. Runtime DI always binds {@see MetricsStore} to RedisMetricsStore.
 */
final class InMemoryMetricsStore implements MetricsStore
{
    /** @var array<string, array<string, int>> */
    private array $counters = [];

    /** @var array<string, array<string, float>> */
    private array $gauges = [];

    /** @var array<string, HistogramSnapshot> */
    private array $histograms = [];

    public function incrementCounter(string $metric, string $labelKey): void
    {
        $this->counters[$metric][$labelKey] = ($this->counters[$metric][$labelKey] ?? 0) + 1;
    }

    public function counterSeries(string $metric): array
    {
        return $this->counters[$metric] ?? [];
    }

    public function setGauge(string $metric, string $labelKey, float $value): void
    {
        $this->gauges[$metric][$labelKey] = $value;
    }

    public function gaugeSeries(string $metric): array
    {
        return $this->gauges[$metric] ?? [];
    }

    public function observeHistogram(string $metric, float $value, array $matchingBucketIndexes): void
    {
        $current = $this->histograms[$metric] ?? new HistogramSnapshot(0, 0.0, []);
        $bucketCounts = $current->bucketCounts;
        if ([] !== $matchingBucketIndexes) {
            $lastIndex = max($matchingBucketIndexes);
            for ($index = 0; $index <= $lastIndex; ++$index) {
                $bucketCounts[$index] ??= 0;
            }
        }
        foreach ($matchingBucketIndexes as $index) {
            $bucketCounts[$index] = ($bucketCounts[$index] ?? 0) + 1;
        }
        ksort($bucketCounts);

        $this->histograms[$metric] = new HistogramSnapshot(
            $current->count + 1,
            $current->sum + $value,
            array_values($bucketCounts),
        );
    }

    public function histogram(string $metric, int $bucketCount): HistogramSnapshot
    {
        $current = $this->histograms[$metric] ?? new HistogramSnapshot(0, 0.0, []);
        $buckets = $current->bucketCounts;
        for ($index = 0; $index < $bucketCount; ++$index) {
            $buckets[$index] ??= 0;
        }
        ksort($buckets);

        return new HistogramSnapshot($current->count, $current->sum, array_values($buckets));
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
