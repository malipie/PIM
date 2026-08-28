<?php

declare(strict_types=1);

namespace App\Tests\Integration\Metrics;

use App\Shared\Infrastructure\Metrics\QueryDurationHistogram;
use App\Shared\Infrastructure\Metrics\RbacMetricsRegistry;
use App\Shared\Infrastructure\Metrics\RedisMetricsStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Throwable;

use const STDERR;

/**
 * AUD-OBS-001 (#3021) — proves the Redis registry contract with independent
 * clients, the same process boundary that separates FrankenPHP workers.
 */
final class SharedMetricsRegistryTest extends TestCase
{
    #[Test]
    public function aggregatesTwoWorkersAndSurvivesOneWorkerRestart(): void
    {
        $dsn = $_ENV['METRICS_REDIS_DSN'] ?? $_SERVER['METRICS_REDIS_DSN'] ?? null;
        self::assertIsString($dsn, 'METRICS_REDIS_DSN is required for the shared-registry integration gate.');
        self::assertNotSame('', $dsn, 'METRICS_REDIS_DSN is required for the shared-registry integration gate.');

        $namespace = 'pim:metrics:test:'.bin2hex(random_bytes(12));
        $scraperStore = new RedisMetricsStore($dsn, $namespace, new NullLogger());

        try {
            // Fork before the parent opens Redis: each child gets a genuinely
            // independent process and connection, matching the state boundary
            // between long-lived runtime workers.
            $workerA = self::spawnProducer($dsn, $namespace, 50, 0.01);
            $workerB = self::spawnProducer($dsn, $namespace, 50, 0.02);
            self::waitForProducer($workerA);
            self::waitForProducer($workerB);

            self::assertTrue($scraperStore->isAvailable(), 'Redis registry must be reachable for this integration gate.');

            // Three independent scrape reads must all expose the same global
            // state, regardless of which worker would receive the HTTP call.
            for ($scrape = 0; $scrape < 3; ++$scrape) {
                $independentScraper = new RedisMetricsStore($dsn, $namespace, new NullLogger());
                $histogram = new QueryDurationHistogram($independentScraper);
                $counters = new RbacMetricsRegistry($independentScraper);

                self::assertSame(100, $histogram->count());
                self::assertEqualsWithDelta(1.5, $histogram->sum(), 0.000001);
                self::assertSame(100, self::metricValue($counters->render(), 'cortex_failed_login_attempts_total{tenant="shared-test"}'));
                self::assertSame(100, self::metricValue($histogram->render(), 'db_query_duration_seconds_count'));
                self::assertStringContainsString('db_query_duration_seconds_sum 1.5', $histogram->render());
            }

            // A fresh process represents worker A after recycling. Existing
            // values remain 100; its next event advances, never resets, them.
            self::assertSame(100, new QueryDurationHistogram($scraperStore)->count());
            $restartedWorker = self::spawnProducer($dsn, $namespace, 1, 0.03);
            self::waitForProducer($restartedWorker);

            self::assertSame(101, new QueryDurationHistogram($scraperStore)->count());
            self::assertSame(101, self::metricValue(new RbacMetricsRegistry($scraperStore)->render(), 'cortex_failed_login_attempts_total{tenant="shared-test"}'));
        } finally {
            $scraperStore->resetNamespace();
        }
    }

    private static function spawnProducer(string $dsn, string $namespace, int $events, float $duration): int
    {
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid, 'Could not fork a metrics producer process.');

        if (0 === $pid) {
            try {
                $store = new RedisMetricsStore($dsn, $namespace, new NullLogger());
                $histogram = new QueryDurationHistogram($store);
                $counters = new RbacMetricsRegistry($store);
                for ($event = 0; $event < $events; ++$event) {
                    $histogram->observe($duration);
                    $counters->incrementFailedLogin('shared-test');
                }
                exit(0);
            } catch (Throwable $exception) {
                fwrite(STDERR, $exception->getMessage()."\n");
                exit(1);
            }
        }

        return $pid;
    }

    private static function waitForProducer(int $pid): void
    {
        $status = null;
        self::assertSame($pid, pcntl_waitpid($pid, $status));
        self::assertIsInt($status);
        self::assertTrue(pcntl_wifexited($status), "Metrics producer {$pid} did not exit normally.");
        self::assertSame(0, pcntl_wexitstatus($status), "Metrics producer {$pid} failed.");
    }

    private static function metricValue(string $exposition, string $metricAndLabels): int
    {
        $pattern = '/^'.preg_quote($metricAndLabels, '/').' (\d+)$/m';
        if (1 !== preg_match($pattern, $exposition, $matches)) {
            self::fail("Metric {$metricAndLabels} not found in exposition.");
        }

        return (int) $matches[1];
    }
}
