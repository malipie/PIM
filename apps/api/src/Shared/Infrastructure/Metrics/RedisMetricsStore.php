<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Metrics;

use Psr\Log\LoggerInterface;
use Redis;
use RuntimeException;
use Throwable;

/**
 * Process-independent metrics registry backed by the instance's existing Redis.
 *
 * Counter/gauge writes are native atomic hash operations. A histogram
 * observation is one Lua transaction, so a scrape can never see its count and
 * buckets half-updated. Redis persistence defines the lifecycle: recycling one
 * FrankenPHP worker does not reset anything; deleting the namespace (or the
 * tenant's Redis volume during deprovisioning) is the controlled reset.
 */
final class RedisMetricsStore implements MetricsStore
{
    private const string HISTOGRAM_OBSERVE_SCRIPT = <<<'LUA'
        redis.call('HINCRBY', KEYS[1], 'count', 1)
        redis.call('HINCRBYFLOAT', KEYS[1], 'sum', ARGV[1])
        for i = 2, #ARGV do
            redis.call('HINCRBY', KEYS[1], 'bucket:' .. ARGV[i], 1)
        end
        return 1
        LUA;

    private ?Redis $redis = null;

    private bool $failureLogged = false;

    private float $retryAfter = 0.0;

    public function __construct(
        private readonly string $dsn,
        private readonly string $namespace,
        private readonly LoggerInterface $logger,
    ) {
        if ('' === trim($namespace)) {
            throw new RuntimeException('Metrics Redis namespace cannot be empty.');
        }
    }

    public function incrementCounter(string $metric, string $labelKey): void
    {
        $this->write(fn (Redis $redis): int|false => $redis->hIncrBy($this->key('counter', $metric), $labelKey, 1));
    }

    public function counterSeries(string $metric): array
    {
        $raw = $this->read(fn (Redis $redis): array|false => $redis->hGetAll($this->key('counter', $metric)), []);
        $series = [];
        foreach ($raw as $labelKey => $value) {
            if (1 === preg_match('/^\d+$/', $value)) {
                $series[(string) $labelKey] = (int) $value;
            }
        }

        return $series;
    }

    public function setGauge(string $metric, string $labelKey, float $value): void
    {
        $this->write(fn (Redis $redis): int|false => $redis->hSet($this->key('gauge', $metric), $labelKey, self::formatFloat($value)));
    }

    public function gaugeSeries(string $metric): array
    {
        $raw = $this->read(fn (Redis $redis): array|false => $redis->hGetAll($this->key('gauge', $metric)), []);
        $series = [];
        foreach ($raw as $labelKey => $value) {
            if (is_numeric($value)) {
                $series[(string) $labelKey] = (float) $value;
            }
        }

        return $series;
    }

    public function observeHistogram(string $metric, float $value, array $matchingBucketIndexes): void
    {
        $arguments = [self::formatFloat($value), ...array_map(static fn (int $index): string => (string) $index, $matchingBucketIndexes)];
        $key = $this->key('histogram', $metric);

        $this->write(
            static fn (Redis $redis): mixed => $redis->eval(self::HISTOGRAM_OBSERVE_SCRIPT, [$key, ...$arguments], 1),
        );
    }

    public function histogram(string $metric, int $bucketCount): HistogramSnapshot
    {
        $raw = $this->read(fn (Redis $redis): array|false => $redis->hGetAll($this->key('histogram', $metric)), []);
        $buckets = [];
        for ($index = 0; $index < $bucketCount; ++$index) {
            $value = $raw['bucket:'.$index] ?? '0';
            $buckets[] = 1 === preg_match('/^\d+$/', $value) ? (int) $value : 0;
        }

        $count = $raw['count'] ?? '0';
        $sum = $raw['sum'] ?? '0';

        return new HistogramSnapshot(
            1 === preg_match('/^\d+$/', $count) ? (int) $count : 0,
            is_numeric($sum) ? (float) $sum : 0.0,
            $buckets,
        );
    }

    public function isAvailable(): bool
    {
        return true === $this->read(static function (Redis $redis): bool {
            $reply = $redis->ping();

            return true === $reply || 'PONG' === $reply || '+PONG' === $reply;
        }, false);
    }

    /**
     * Controlled reset for deprovisioning and isolated integration tests.
     * Only keys below the exact configured namespace are candidates.
     */
    public function resetNamespace(): void
    {
        $this->write(function (Redis $redis): void {
            $iterator = null;
            do {
                $keys = $redis->scan($iterator, $this->namespace.':*', 100);
                if (false !== $keys && [] !== $keys) {
                    $redis->del($keys);
                }
            } while (0 !== $iterator);
        });
    }

    private function key(string $kind, string $metric): string
    {
        if (1 !== preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:]*$/', $metric)) {
            throw new RuntimeException("Invalid Prometheus metric name: {$metric}");
        }

        return $this->namespace.':'.$kind.':'.$metric;
    }

    /**
     * @template T
     *
     * @param callable(Redis): T $operation
     * @param T                  $fallback
     *
     * @return T
     */
    private function read(callable $operation, mixed $fallback): mixed
    {
        try {
            $result = $operation($this->connection());
            $this->failureLogged = false;

            return false === $result ? $fallback : $result;
        } catch (Throwable $exception) {
            $this->onFailure($exception);

            return $fallback;
        }
    }

    /**
     * @param callable(Redis): mixed $operation
     */
    private function write(callable $operation): void
    {
        try {
            $operation($this->connection());
            $this->failureLogged = false;
        } catch (Throwable $exception) {
            $this->onFailure($exception);
        }
    }

    private function connection(): Redis
    {
        if ($this->redis instanceof Redis && $this->redis->isConnected()) {
            return $this->redis;
        }
        if (microtime(true) < $this->retryAfter) {
            throw new RuntimeException('Metrics Redis reconnect is cooling down after a failed attempt.');
        }

        $parts = parse_url($this->dsn);
        if (false === $parts || !isset($parts['scheme'], $parts['host']) || !\in_array($parts['scheme'], ['redis', 'rediss'], true)) {
            throw new RuntimeException('METRICS_REDIS_DSN must be a redis:// or rediss:// URL with a host.');
        }

        $redis = new Redis();
        $host = ('rediss' === $parts['scheme'] ? 'tls://' : '').$parts['host'];
        if (!$redis->connect($host, $parts['port'] ?? 6379, 1.0)) {
            throw new RuntimeException('Could not connect to the metrics Redis registry.');
        }
        if (isset($parts['pass']) && '' !== $parts['pass'] && !$redis->auth(rawurldecode($parts['pass']))) {
            throw new RuntimeException('Could not authenticate to the metrics Redis registry.');
        }
        $database = isset($parts['path']) ? trim($parts['path'], '/') : '';
        if ('' !== $database && (!$redis->select((int) $database))) {
            throw new RuntimeException('Could not select the metrics Redis database.');
        }

        $this->redis = $redis;
        $this->retryAfter = 0.0;

        return $redis;
    }

    private function onFailure(Throwable $exception): void
    {
        $this->redis = null;
        $now = microtime(true);
        if ($this->retryAfter <= $now) {
            $this->retryAfter = $now + 5.0;
        }
        if ($this->failureLogged) {
            return;
        }
        $this->failureLogged = true;
        $this->logger->warning('Shared Redis metrics registry is unavailable; business operation continues without telemetry.', [
            'exception' => $exception,
        ]);
    }

    private static function formatFloat(float $value): string
    {
        return rtrim(rtrim(\sprintf('%.12F', $value), '0'), '.');
    }
}
