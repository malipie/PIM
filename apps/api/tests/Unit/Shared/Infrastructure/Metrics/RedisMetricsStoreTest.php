<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Metrics;

use App\Shared\Infrastructure\Metrics\RedisMetricsStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RedisMetricsStoreTest extends TestCase
{
    #[Test]
    public function telemetryFailureNeverBreaksTheBusinessCaller(): void
    {
        $store = new RedisMetricsStore('invalid://registry', 'pim:metrics:test:failure', new NullLogger());

        // The first write detects the outage; subsequent writes hit the
        // reconnect cooldown instead of paying a connection timeout per query.
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $store->incrementCounter('test_counter_total', '');
            $store->observeHistogram('test_duration_seconds', 0.1, [0, 1]);
        }

        self::assertFalse($store->isAvailable());
        self::assertSame([], $store->counterSeries('test_counter_total'));
        self::assertSame(0, $store->histogram('test_duration_seconds', 2)->count);
    }
}
