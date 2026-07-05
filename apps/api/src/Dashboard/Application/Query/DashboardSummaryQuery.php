<?php

declare(strict_types=1);

namespace App\Dashboard\Application\Query;

use App\Catalog\Contracts\Query\ChannelCompletenessPort;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

/**
 * DASH-04 (#2255, ADR-0026) — the dashboard KPI + completeness aggregate:
 * one COUNT-FILTER pass over product-kind objects (totals, cumulative
 * completeness thresholds, avg, 30-day created delta) plus the per-channel
 * aggregate from the Catalog Contracts port.
 *
 * Completeness deltas (`delta30d`, `weeklyDeltaPoints`) stay null until the
 * daily snapshots land (DASH-05); `openAlerts` stays null until the alert
 * aggregator lands (DASH-09). The FE renders "no trend" for nulls — never
 * a fabricated arrow (NUI-02).
 */
final readonly class DashboardSummaryQuery
{
    /** Publish-ready threshold, matching the FE PUBLISH_READY_THRESHOLD. */
    private const int READY_THRESHOLD = 80;

    /** Cumulative "at least N%" thresholds the distribution strip reads. */
    private const array THRESHOLDS = [25, 50, self::READY_THRESHOLD, 100];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
        private ChannelCompletenessPort $channelCompleteness,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Cannot build the dashboard summary without a current tenant.');
        }

        $filters = [];
        foreach (self::THRESHOLDS as $threshold) {
            $filters[] = \sprintf(
                'COUNT(*) FILTER (WHERE completeness_pct >= %d) AS gte_%d',
                $threshold,
                $threshold,
            );
        }

        // tenant-safe: explicit tenant_id predicate (raw SQL bypasses the
        // Doctrine TenantFilter); RLS is the backstop.
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT COUNT(*) AS total, '
            .'COALESCE(AVG(completeness_pct), 0) AS avg_pct, '
            ."COUNT(*) FILTER (WHERE created_at >= NOW() - INTERVAL '30 days') AS created_30d, "
            .implode(', ', $filters)
            ." FROM objects WHERE tenant_id = :tenant AND kind = 'product'",
            ['tenant' => $tenant->getId()->toRfc4122()],
        );

        $total = $this->intOf($row['total'] ?? 0);
        $ready = $this->intOf($row['gte_'.self::READY_THRESHOLD] ?? 0);
        $avgRaw = $row['avg_pct'] ?? 0;

        $buckets = [];
        foreach (self::THRESHOLDS as $threshold) {
            $buckets[] = [
                'gte' => $threshold,
                'count' => $this->intOf($row['gte_'.$threshold] ?? 0),
            ];
        }

        $channels = [];
        foreach ($this->channelCompleteness->perChannel(self::READY_THRESHOLD) as $channel) {
            $channels[] = [
                'code' => $channel->channelCode,
                'name' => $channel->channelName,
                'avgPct' => $channel->avgPct,
                'readyCount' => $channel->readyCount,
            ];
        }

        return [
            'products' => [
                'total' => $total,
                'delta30d' => $this->intOf($row['created_30d'] ?? 0),
            ],
            'publishReady' => [
                'count' => $ready,
                'pct' => $total > 0 ? (int) round($ready / $total * 100) : 0,
                'delta30d' => null,
            ],
            'avgCompleteness' => [
                'pct' => (int) round(\is_numeric($avgRaw) ? (float) $avgRaw : 0.0),
                'delta30d' => null,
                'weeklyDeltaPoints' => null,
            ],
            'buckets' => $buckets,
            'channels' => $channels,
            'openAlerts' => null,
        ];
    }

    private function intOf(mixed $value): int
    {
        return (int) (\is_scalar($value) ? $value : 0);
    }
}
