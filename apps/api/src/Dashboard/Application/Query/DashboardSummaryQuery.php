<?php

declare(strict_types=1);

namespace App\Dashboard\Application\Query;

use App\Catalog\Contracts\Query\ChannelCompletenessPort;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * DASH-04 (#2255, ADR-0026) — the dashboard KPI + completeness aggregate:
 * one COUNT-FILTER pass over product-kind objects (totals, cumulative
 * completeness thresholds, avg, 30-day created delta) plus the per-channel
 * aggregate from the Catalog Contracts port.
 *
 * DASH-05 (#2257) — completeness deltas come from `dashboard_snapshots`
 * (daily job): current value minus the snapshot at the 30d/7d horizon.
 * No snapshot at the horizon ⇒ null ⇒ the FE renders "no trend" — never
 * a fabricated arrow (NUI-02). `openAlerts` stays null until the alert
 * aggregator lands (DASH-09).
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
        private AlertFeedAggregator $alerts,
    ) {
    }

    /**
     * The raw aggregates for the current tenant — shared by the summary
     * response and the daily snapshot command.
     *
     * @param bool $withChannels #2831 — false skips the per-channel
     *                           completeness probe for callers without
     *                           `channel.read`; the snapshot command and
     *                           channel-entitled callers keep it
     */
    public function aggregates(bool $withChannels = true): DashboardAggregates
    {
        $tenant = $this->currentTenant();

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

        $buckets = [];
        foreach (self::THRESHOLDS as $threshold) {
            $buckets[$threshold] = $this->intOf($row['gte_'.$threshold] ?? 0);
        }
        $avgRaw = $row['avg_pct'] ?? 0;

        return new DashboardAggregates(
            productsTotal: $this->intOf($row['total'] ?? 0),
            createdLast30d: $this->intOf($row['created_30d'] ?? 0),
            publishReadyCount: $buckets[self::READY_THRESHOLD],
            avgCompletenessPct: (int) round(\is_numeric($avgRaw) ? (float) $avgRaw : 0.0),
            cumulativeBuckets: $buckets,
            channels: $withChannels ? $this->channelCompleteness->perChannel(self::READY_THRESHOLD) : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Uuid $userId, bool $withChannels = true): array
    {
        $tenant = $this->currentTenant();
        $aggregates = $this->aggregates($withChannels);
        $horizons = $this->snapshotHorizons($tenant);

        $ready30 = $horizons[30]['publish_ready_count'] ?? null;
        $avg30 = $horizons[30]['avg_completeness_pct'] ?? null;
        $avg7 = $horizons[7]['avg_completeness_pct'] ?? null;

        $buckets = [];
        foreach ($aggregates->cumulativeBuckets as $threshold => $count) {
            $buckets[] = ['gte' => $threshold, 'count' => $count];
        }

        $channels = [];
        foreach ($aggregates->channels as $channel) {
            $channels[] = [
                'code' => $channel->channelCode,
                'name' => $channel->channelName,
                'avgPct' => $channel->avgPct,
                'readyCount' => $channel->readyCount,
            ];
        }

        return [
            'products' => [
                'total' => $aggregates->productsTotal,
                'delta30d' => $aggregates->createdLast30d,
            ],
            'publishReady' => [
                'count' => $aggregates->publishReadyCount,
                'pct' => $aggregates->publishReadyPct(),
                'delta30d' => null === $ready30 ? null : $aggregates->publishReadyCount - $ready30,
            ],
            'avgCompleteness' => [
                'pct' => $aggregates->avgCompletenessPct,
                'delta30d' => null === $avg30 ? null : $aggregates->avgCompletenessPct - $avg30,
                'weeklyDeltaPoints' => null === $avg7 ? null : $aggregates->avgCompletenessPct - $avg7,
            ],
            'buckets' => $buckets,
            'channels' => $channels,
            // DASH-09 — unread alerts in the last 24h, per-user RBAC view.
            'openAlerts' => ['count' => $this->alerts->openCount($userId)],
        ];
    }

    /**
     * Snapshot rows at exactly the 30d and 7d horizons, keyed by horizon.
     * A missed cron day simply yields no delta for that horizon — honest
     * nulls over approximated windows.
     *
     * @return array<int, array{publish_ready_count: int, avg_completeness_pct: int}>
     */
    private function snapshotHorizons(Tenant $tenant): array
    {
        // tenant-safe: explicit tenant_id predicate (see aggregates()).
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT snapshot_date, publish_ready_count, avg_completeness_pct '
            .'FROM dashboard_snapshots '
            .'WHERE tenant_id = :tenant '
            .'AND snapshot_date IN (CURRENT_DATE - 30, CURRENT_DATE - 7)',
            ['tenant' => $tenant->getId()->toRfc4122()],
        );

        $horizon30 = $this->dateString('-30 days');
        $horizon7 = $this->dateString('-7 days');

        $byHorizon = [];
        foreach ($rows as $row) {
            $date = \is_string($row['snapshot_date'] ?? null) ? $row['snapshot_date'] : '';
            $values = [
                'publish_ready_count' => $this->intOf($row['publish_ready_count'] ?? 0),
                'avg_completeness_pct' => $this->intOf($row['avg_completeness_pct'] ?? 0),
            ];
            if ($date === $horizon30) {
                $byHorizon[30] = $values;
            }
            if ($date === $horizon7) {
                $byHorizon[7] = $values;
            }
        }

        return $byHorizon;
    }

    private function currentTenant(): Tenant
    {
        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Cannot build the dashboard summary without a current tenant.');
        }

        return $tenant;
    }

    private function dateString(string $modifier): string
    {
        return new DateTimeImmutable('today')->modify($modifier)->format('Y-m-d');
    }

    private function intOf(mixed $value): int
    {
        return (int) (\is_scalar($value) ? $value : 0);
    }
}
