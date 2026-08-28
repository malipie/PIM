<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Metrics;

/**
 * Prometheus histogram for Doctrine DBAL query durations (audit MEDIUM-003).
 *
 * Logging is OFF in production (memory discipline — sekcja 3.10 of the
 * architecture), but ops still needs to see when a query starts taking
 * meaningfully longer. The histogram aggregates each query's wall-clock
 * runtime into bucketed counters that Prometheus scrapes every N
 * seconds, so p95 / p99 alerts can be wired to `histogram_quantile`
 * without re-enabling SQL logging.
 *
 * Runtime state lives in the shared {@see MetricsStore}, so every FrankenPHP
 * worker contributes to one monotonic histogram and a random scrape target
 * sees the complete instance. Direct construction uses an isolated in-memory
 * store for unit tests only.
 */
final class QueryDurationHistogram
{
    /**
     * Bucket upper bounds in seconds. Aligned with Prometheus client_php
     * defaults; covers sub-millisecond cache hits up to 10s long-running
     * reports.
     *
     * @var list<float>
     */
    public const array DEFAULT_BUCKETS = [
        0.001,
        0.005,
        0.01,
        0.025,
        0.05,
        0.1,
        0.25,
        0.5,
        1.0,
        2.5,
        5.0,
        10.0,
    ];

    /** @var list<float> */
    private array $buckets;

    private readonly MetricsStore $store;

    /**
     * @param list<float>|null $buckets ascending bucket boundaries; null
     *                                  uses {@see DEFAULT_BUCKETS}
     */
    public function __construct(?MetricsStore $store = null, ?array $buckets = null)
    {
        $this->store = $store ?? new InMemoryMetricsStore();
        $this->buckets = $buckets ?? self::DEFAULT_BUCKETS;
    }

    public function observe(float $durationSeconds): void
    {
        $matchingBucketIndexes = [];
        foreach ($this->buckets as $index => $upperBound) {
            if ($durationSeconds <= $upperBound) {
                $matchingBucketIndexes[] = $index;
            }
        }

        $this->store->observeHistogram('db_query_duration_seconds', $durationSeconds, $matchingBucketIndexes);
    }

    /**
     * Render a Prometheus exposition snippet (no leading TYPE/HELP — the
     * caller composes them). Histogram bucket lines use the canonical
     * `le="<upper>"` label including the `+Inf` overflow bucket. The
     * cumulative `_count` is identical to the `+Inf` bucket per spec.
     */
    public function render(): string
    {
        $snapshot = $this->store->histogram('db_query_duration_seconds', \count($this->buckets));
        $lines = [];
        foreach ($this->buckets as $index => $upperBound) {
            $lines[] = \sprintf(
                'db_query_duration_seconds_bucket{le="%s"} %d',
                self::formatBucketBound($upperBound),
                $snapshot->bucketCounts[$index],
            );
        }
        $lines[] = \sprintf('db_query_duration_seconds_bucket{le="+Inf"} %d', $snapshot->count);
        $lines[] = \sprintf('db_query_duration_seconds_sum %s', self::formatFloat($snapshot->sum));
        $lines[] = \sprintf('db_query_duration_seconds_count %d', $snapshot->count);

        return implode("\n", $lines);
    }

    public function count(): int
    {
        return $this->store->histogram('db_query_duration_seconds', \count($this->buckets))->count;
    }

    public function sum(): float
    {
        return $this->store->histogram('db_query_duration_seconds', \count($this->buckets))->sum;
    }

    /**
     * @return list<float>
     */
    public function buckets(): array
    {
        return $this->buckets;
    }

    /**
     * @return array<int, int>
     */
    public function bucketCounts(): array
    {
        return $this->store->histogram('db_query_duration_seconds', \count($this->buckets))->bucketCounts;
    }

    private static function formatBucketBound(float $value): string
    {
        // Match Prometheus convention: short decimals without trailing
        // zeros so `0.005` stays `0.005` and `1.0` becomes `1`.
        $formatted = rtrim(rtrim(\sprintf('%.6f', $value), '0'), '.');

        return '' === $formatted ? '0' : $formatted;
    }

    private static function formatFloat(float $value): string
    {
        return rtrim(rtrim(\sprintf('%.6f', $value), '0'), '.');
    }
}
