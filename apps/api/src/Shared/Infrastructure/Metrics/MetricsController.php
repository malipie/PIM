<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Metrics;

use App\Identity\Contracts\Attribute\NoPermissionRequired;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Prometheus-compatible scrape endpoint.
 *
 * Surface today:
 * - `frankenphp_worker_memory_bytes` (architecture sekcja 3.10 guardrail).
 * - `frankenphp_worker_peak_memory_bytes`, `frankenphp_worker_pid` companions.
 * - `db_query_duration_seconds` histogram (audit MEDIUM-003) — emitted by
 *   the {@see \App\Shared\Infrastructure\Doctrine\Middleware\QueryTimingMiddleware}
 *   so ops can wire `histogram_quantile(0.95, …)` / `0.99` alerts on
 *   slow DB without re-enabling SQL logging in production.
 * - `pim_postgres_object_values_*` gauges — autovacuum health of the hot
 *   EAV table, read from the current tenant database's statistics view.
 *
 * Memory/PID gauges describe whichever worker handled this scrape and retain
 * rolling-max semantics. Counters and histograms are shared through Redis, so
 * every worker contributes to the same monotonic instance-wide series.
 */
final readonly class MetricsController
{
    public function __construct(
        private QueryDurationHistogram $queryHistogram,
        private RbacMetricsRegistry $rbacMetrics,
        private PostgresMaintenanceMetrics $postgresMaintenanceMetrics,
        private MetricsStore $metricsStore,
    ) {
    }

    #[Route(path: '/api/metrics', name: 'app_metrics', methods: ['GET'])]
    #[NoPermissionRequired(reason: 'Prometheus scrape endpoint — reachable only from the internal docker network (api:80); the edge Caddy 404s /api/metrics (#2729), so it is never exposed to the public origin and needs no RBAC.')]
    public function __invoke(): Response
    {
        $resident = \memory_get_usage(true);
        $peak = \memory_get_peak_usage(true);
        $pid = \getmypid();
        // Read Postgres first so this query is represented in the DB duration
        // histogram rendered immediately afterwards.
        $postgresMetrics = $this->postgresMaintenanceMetrics->render();
        $queryMetrics = $this->queryHistogram->render();
        $rbacMetrics = $this->rbacMetrics->render();
        $sharedRegistryUp = (int) $this->metricsStore->isAvailable();

        $body = <<<METRICS
            # HELP frankenphp_worker_memory_bytes Resident PHP memory of the FrankenPHP worker that handled the scrape.
            # TYPE frankenphp_worker_memory_bytes gauge
            frankenphp_worker_memory_bytes {$resident}
            # HELP frankenphp_worker_peak_memory_bytes Peak PHP memory ever held by this worker since boot.
            # TYPE frankenphp_worker_peak_memory_bytes gauge
            frankenphp_worker_peak_memory_bytes {$peak}
            # HELP frankenphp_worker_pid Process id of the worker that handled the scrape.
            # TYPE frankenphp_worker_pid gauge
            frankenphp_worker_pid {$pid}
            # HELP pim_shared_metrics_registry_up Whether the shared Redis metrics registry is reachable from this worker.
            # TYPE pim_shared_metrics_registry_up gauge
            pim_shared_metrics_registry_up {$sharedRegistryUp}
            # HELP db_query_duration_seconds Wall-clock duration of every Doctrine DBAL query handled by this instance across all workers.
            # TYPE db_query_duration_seconds histogram
            {$queryMetrics}
            {$rbacMetrics}
            {$postgresMetrics}
            METRICS;

        return new Response($body, Response::HTTP_OK, ['content-type' => 'text/plain; version=0.0.4']);
    }
}
