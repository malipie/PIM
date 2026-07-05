<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Contracts\Query\ChannelCompleteness;
use App\Catalog\Contracts\Query\ChannelCompletenessPort;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

/**
 * DASH-03 (#2253) — SQL aggregation over the per-channel completeness
 * section of `objects.completeness` (JSONB contract:
 * docs/api/jsonb-schemas.md §3; the values are maintained by
 * AttributesIndexedRebuilder — this port only READS).
 *
 * Scope: product-kind objects only — the dashboard widget speaks in SKU.
 * Channel display names are resolved from the `channels` table by code
 * (plain SQL join keeps this Catalog-internal; Deptrac governs class
 * dependencies, not table reads) and fall back to the raw code for keys
 * whose channel row no longer exists.
 */
final readonly class SqlChannelCompletenessReport implements ChannelCompletenessPort
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
    ) {
    }

    public function perChannel(int $thresholdPct = 80): array
    {
        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Cannot aggregate channel completeness without a current tenant.');
        }
        $tenantId = $tenant->getId()->toRfc4122();

        $connection = $this->entityManager->getConnection();

        // tenant-safe: explicit tenant_id predicate (raw SQL bypasses the
        // Doctrine TenantFilter); RLS is the backstop.
        $rows = $connection->fetchAllAssociative(
            'SELECT kv.key AS channel_code, '
            .'ROUND(AVG((kv.value)::numeric))::int AS avg_pct, '
            .'COUNT(*) FILTER (WHERE (kv.value)::numeric >= :threshold) AS ready_count, '
            .'MAX(c.name) AS channel_name '
            .'FROM objects o '
            ."CROSS JOIN LATERAL jsonb_each_text(o.completeness->'per_channel') AS kv "
            .'LEFT JOIN channels c ON c.tenant_id = o.tenant_id AND c.code = kv.key '
            ."WHERE o.tenant_id = :tenant AND o.kind = 'product' "
            .'GROUP BY kv.key '
            .'ORDER BY avg_pct ASC, kv.key ASC',
            [
                'threshold' => $thresholdPct,
                'tenant' => $tenantId,
            ],
        );

        $report = [];
        foreach ($rows as $row) {
            $code = \is_string($row['channel_code'] ?? null) ? $row['channel_code'] : '';
            if ('' === $code) {
                continue;
            }
            $name = \is_string($row['channel_name'] ?? null) && '' !== $row['channel_name']
                ? $row['channel_name']
                : $code;
            $avgRaw = $row['avg_pct'] ?? 0;
            $readyRaw = $row['ready_count'] ?? 0;

            $report[] = new ChannelCompleteness(
                channelCode: $code,
                channelName: $name,
                avgPct: (int) (\is_numeric($avgRaw) ? $avgRaw : 0),
                readyCount: (int) (\is_numeric($readyRaw) ? $readyRaw : 0),
            );
        }

        return $report;
    }
}
