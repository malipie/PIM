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
 *
 * Numbers reported are "for whichever worker handled THIS scrape" — fine
 * for single-worker dev. In production with multiple FrankenPHP workers
 * behind the edge Caddy, scraping reaches one randomly, so alert
 * thresholds watch the rolling max across scrapes.
 */
final readonly class MetricsController
{
    public function __construct(
        private QueryDurationHistogram $queryHistogram,
        private RbacMetricsRegistry $rbacMetrics,
    ) {
    }

    #[Route(path: '/api/metrics', name: 'app_metrics', methods: ['GET'])]
    #[NoPermissionRequired(reason: 'Prometheus scrape endpoint — reachable only from the internal docker network (api:80); the edge Caddy 404s /api/metrics (#2729), so it is never exposed to the public origin and needs no RBAC.')]
    public function __invoke(): Response
    {
        $resident = \memory_get_usage(true);
        $peak = \memory_get_peak_usage(true);
        $pid = \getmypid();
        $queryMetrics = $this->queryHistogram->render();
        $rbacMetrics = $this->rbacMetrics->render();

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
            # HELP db_query_duration_seconds Wall-clock duration of every Doctrine DBAL query handled by this worker since boot.
            # TYPE db_query_duration_seconds histogram
            {$queryMetrics}
            {$rbacMetrics}
            METRICS;

        return new Response($body, Response::HTTP_OK, ['content-type' => 'text/plain; version=0.0.4']);
    }
}
