<?php

declare(strict_types=1);

namespace App\Tests\Integration\Metrics;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * `/api/metrics` Prometheus surface — locks in the metric families the
 * scrape endpoint is expected to expose.
 *
 * The histogram lines (audit MEDIUM-003) populate after the kernel
 * issues at least one DBAL query, which the request lifecycle already
 * does (RequestTenantSubscriber reads `tenants`). The test verifies
 * the response exposition format, not specific bucket counts —
 * Prometheus tolerates eventually-consistent counter values.
 */
final class MetricsEndpointTest extends WebTestCase
{
    use ResetDatabase;

    #[Test]
    public function exposesWorkerAndDbHistogramMetrics(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/metrics');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            'text/plain',
            (string) $client->getResponse()->headers->get('content-type'),
        );

        $body = (string) $client->getResponse()->getContent();

        // Existing FrankenPHP worker gauges stay intact.
        self::assertStringContainsString('frankenphp_worker_memory_bytes', $body);
        self::assertStringContainsString('frankenphp_worker_peak_memory_bytes', $body);
        self::assertStringContainsString('frankenphp_worker_pid', $body);

        // New DB histogram surface (audit MEDIUM-003).
        self::assertStringContainsString('# TYPE db_query_duration_seconds histogram', $body);
        self::assertStringContainsString('db_query_duration_seconds_bucket{le="0.001"}', $body);
        self::assertStringContainsString('db_query_duration_seconds_bucket{le="0.5"}', $body);
        self::assertStringContainsString('db_query_duration_seconds_bucket{le="+Inf"}', $body);
        self::assertStringContainsString('db_query_duration_seconds_sum ', $body);
        self::assertStringContainsString('db_query_duration_seconds_count ', $body);

        // PostgreSQL maintenance gauges feed the autovacuum alerts. They are
        // read from this instance's own database, so tenant labels remain the
        // responsibility of the Prometheus scrape target.
        self::assertStringContainsString('pim_postgres_maintenance_scrape_success 1', $body);
        self::assertStringContainsString('pim_postgres_object_values_live_tuples ', $body);
        self::assertStringContainsString('pim_postgres_object_values_dead_tuples ', $body);
        self::assertStringContainsString('pim_postgres_object_values_inserts_since_vacuum ', $body);
        self::assertStringContainsString('pim_postgres_object_values_never_vacuumed ', $body);
    }

    #[Test]
    public function histogramCountIsPositiveAfterTheRequestLifecycle(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/metrics');

        $body = (string) $client->getResponse()->getContent();
        if (1 !== preg_match('/^db_query_duration_seconds_count (\d+)$/m', $body, $matches)) {
            self::fail('db_query_duration_seconds_count line not found in response.');
        }

        // The request lifecycle (RequestTenantSubscriber + Symfony's
        // SecurityListener etc.) hits the database before the controller
        // runs; the histogram must have observed at least one query.
        self::assertGreaterThan(
            0,
            (int) $matches[1],
            'Histogram count must be positive after at least one request through the kernel.',
        );
    }
}
