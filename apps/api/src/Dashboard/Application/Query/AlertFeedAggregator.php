<?php

declare(strict_types=1);

namespace App\Dashboard\Application\Query;

use App\Catalog\Contracts\Query\ChannelCompletenessPort;
use App\Identity\Contracts\Policy\PermissionCheckerInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Uid\Uuid;

use const DATE_ATOM;

/**
 * DASH-09 (#2265, ADR-0026) — the action-center feed, aggregated ON THE
 * FLY from the four status tables + the snapshot-based completeness-drop
 * detector (operator decision 2026-07-04: no materialized Alert entity;
 * the time window replaces TTL, `dashboard_alert_acks` holds the only
 * persisted state, keyed by deterministic fingerprints).
 *
 * Items carry STRUCTURED params, not pre-baked strings — the FE composes
 * titles via i18n so pl/en stay in parity.
 *
 * Per-type RBAC (brief §8.4): a user only sees alert types whose source
 * module they can read; the checker codes mirror the source controllers'
 * `#[RequiresPermission]` gates. The completeness-drop type rides on the
 * route's own products.view gate.
 *
 * @phpstan-type AlertItem array{fingerprint: string, type: string, severity: string, occurredAt: string, params: array<string, mixed>, context: array<string, string>}
 */
final readonly class AlertFeedAggregator
{
    public const array WINDOWS = ['1d' => 1, '7d' => 7, '30d' => 30];

    /** Publish-ready threshold — matches DashboardSummaryQuery. */
    private const int READY_THRESHOLD = 80;

    /** type => permission code required to SEE alerts of that type. */
    private const array TYPE_PERMISSIONS = [
        'sync_run' => 'integration.admin',
        'import_session' => 'import_session.read',
        'feed_run' => 'exports.view_all',
        'webhook' => 'api_profile.read',
        'completeness_drop' => null,
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
        private ChannelCompletenessPort $channelCompleteness,
        private PermissionCheckerInterface $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function alerts(Uuid $userId, string $window, int $limit): array
    {
        $days = self::WINDOWS[$window] ?? null;
        if (null === $days) {
            throw new InvalidArgumentException(\sprintf('Unknown window "%s".', $window));
        }
        $tenant = $this->currentTenant();
        $from = new DateTimeImmutable(\sprintf('-%d days', $days));

        $items = array_merge(
            $this->syncRunAlerts($tenant, $from),
            $this->importSessionAlerts($tenant, $from),
            $this->feedRunAlerts($tenant, $from),
            $this->webhookAlerts($tenant, $from),
            $this->completenessDropAlerts($tenant),
        );

        // Per-type RBAC filter (brief §8.4) — degraded types simply vanish.
        $items = array_values(array_filter($items, function (array $item) use ($userId): bool {
            $permission = self::TYPE_PERMISSIONS[$item['type']] ?? null;

            return null === $permission || $this->permissionChecker->userHasPermission($userId, $permission);
        }));

        // Acked fingerprints disappear from the feed (tenant-scoped state).
        $acked = $this->ackedFingerprints($tenant, array_column($items, 'fingerprint'));
        $items = array_values(array_filter(
            $items,
            static fn (array $item): bool => !\in_array($item['fingerprint'], $acked, true),
        ));

        // Critical first, newest first within a severity (mock order).
        usort($items, static function (array $a, array $b): int {
            if ($a['severity'] !== $b['severity']) {
                return 'critical' === $a['severity'] ? -1 : 1;
            }

            return strcmp($b['occurredAt'], $a['occurredAt']);
        });

        $total = \count($items);
        $critical = \count(array_filter($items, static fn (array $i): bool => 'critical' === $i['severity']));

        return [
            'total' => $total,
            'critical' => $critical,
            'warnings' => $total - $critical,
            'allCount' => $total,
            'items' => \array_slice($items, 0, $limit),
        ];
    }

    /** Unread alert count for the KPI tile (24h window). */
    public function openCount(Uuid $userId): int
    {
        $feed = $this->alerts($userId, '1d', 0);
        $total = $feed['total'] ?? 0;

        return (int) (\is_scalar($total) ? $total : 0);
    }

    public function acknowledge(string $fingerprint, ?Uuid $userId): void
    {
        $tenant = $this->currentTenant();

        // tenant-safe: explicit tenant_id VALUE (RLS backstop); idempotent
        // by the unique (tenant_id, fingerprint) index.
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO dashboard_alert_acks (id, tenant_id, fingerprint, acked_by, acked_at) '
            .'VALUES (:id, :tenant, :fingerprint, :user, NOW()) '
            .'ON CONFLICT (tenant_id, fingerprint) DO NOTHING',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'tenant' => $tenant->getId()->toRfc4122(),
                'fingerprint' => $fingerprint,
                'user' => $userId?->toRfc4122(),
            ],
        );
    }

    /**
     * @return list<AlertItem>
     */
    private function syncRunAlerts(Tenant $tenant, DateTimeImmutable $from): array
    {
        // tenant-safe: explicit tenant_id predicate (raw SQL bypasses the
        // Doctrine TenantFilter); RLS is the backstop. Same note applies to
        // every source query below.
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT r.id, r.status, r.failed_count, r.finished_at, c.name AS connection_name, c.id AS connection_id '
            .'FROM integration_sync_runs r '
            .'JOIN integration_sync_bindings b ON b.id = r.binding_id '
            .'JOIN integration_connections c ON c.id = b.connection_id '
            .'WHERE r.tenant_id = :tenant AND r.finished_at >= :from '
            ."AND (r.status = 'failed' OR (r.status = 'partial' AND r.failed_count > 0))",
            ['tenant' => $tenant->getId()->toRfc4122(), 'from' => $from->format(DATE_ATOM)],
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'fingerprint' => 'sync_run:'.$this->str($row['id'] ?? ''),
                'type' => 'sync_run',
                'severity' => 'critical',
                'occurredAt' => $this->str($row['finished_at'] ?? ''),
                'params' => [
                    'sourceName' => $this->str($row['connection_name'] ?? ''),
                    'failedCount' => $this->intOf($row['failed_count'] ?? 0),
                    'status' => $this->str($row['status'] ?? ''),
                ],
                'context' => [
                    'syncRunId' => $this->str($row['id'] ?? ''),
                    'connectionId' => $this->str($row['connection_id'] ?? ''),
                ],
            ];
        }

        return $items;
    }

    /**
     * @return list<AlertItem>
     */
    private function importSessionAlerts(Tenant $tenant, DateTimeImmutable $from): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT id, file_name, status, error_count, completed_at FROM import_sessions '
            .'WHERE tenant_id = :tenant AND completed_at >= :from '
            ."AND (status = 'failed' OR (status = 'partial' AND error_count > 0))",
            ['tenant' => $tenant->getId()->toRfc4122(), 'from' => $from->format(DATE_ATOM)],
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'fingerprint' => 'import_session:'.$this->str($row['id'] ?? ''),
                'type' => 'import_session',
                'severity' => 'critical',
                'occurredAt' => $this->str($row['completed_at'] ?? ''),
                'params' => [
                    'sourceName' => $this->str($row['file_name'] ?? ''),
                    'errorCount' => $this->intOf($row['error_count'] ?? 0),
                    'status' => $this->str($row['status'] ?? ''),
                ],
                'context' => ['sessionId' => $this->str($row['id'] ?? '')],
            ];
        }

        return $items;
    }

    /**
     * @return list<AlertItem>
     */
    private function feedRunAlerts(Tenant $tenant, DateTimeImmutable $from): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT r.id, r.feed_profile_id, r.error_message, COALESCE(r.completed_at, r.started_at) AS occurred_at, '
            .'p.name AS feed_name '
            .'FROM feed_runs r '
            .'LEFT JOIN feed_profiles p ON p.id = r.feed_profile_id '
            ."WHERE r.tenant_id = :tenant AND r.status = 'error' "
            .'AND COALESCE(r.completed_at, r.started_at) >= :from',
            ['tenant' => $tenant->getId()->toRfc4122(), 'from' => $from->format(DATE_ATOM)],
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'fingerprint' => 'feed_run:'.$this->str($row['id'] ?? ''),
                'type' => 'feed_run',
                'severity' => 'warning',
                'occurredAt' => $this->str($row['occurred_at'] ?? ''),
                'params' => [
                    'sourceName' => '' !== $this->str($row['feed_name'] ?? '')
                        ? $this->str($row['feed_name'] ?? '')
                        : $this->str($row['feed_profile_id'] ?? ''),
                    'reason' => $this->str($row['error_message'] ?? ''),
                ],
                'context' => ['feedProfileId' => $this->str($row['feed_profile_id'] ?? '')],
            ];
        }

        return $items;
    }

    /**
     * Dead-letter deliveries grouped per (profile, event, day) — the mock's
     * "3 dostawy w dead-letter" aggregation.
     *
     * @return list<AlertItem>
     */
    private function webhookAlerts(Tenant $tenant, DateTimeImmutable $from): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT d.profile_id, d.event_type, d.updated_at::date AS day, COUNT(*) AS deliveries, '
            .'MAX(d.updated_at) AS last_at, MAX(d.http_status) AS http_status, MAX(p.name) AS profile_name '
            .'FROM api_webhook_deliveries d '
            .'LEFT JOIN api_profiles p ON p.id = d.profile_id '
            ."WHERE d.tenant_id = :tenant AND d.status = 'failed' AND d.updated_at >= :from "
            .'GROUP BY d.profile_id, d.event_type, d.updated_at::date',
            ['tenant' => $tenant->getId()->toRfc4122(), 'from' => $from->format(DATE_ATOM)],
        );

        $items = [];
        foreach ($rows as $row) {
            $profileId = $this->str($row['profile_id'] ?? '');
            $eventType = $this->str($row['event_type'] ?? '');
            $items[] = [
                'fingerprint' => \sprintf('webhook:%s:%s:%s', $profileId, $eventType, $this->str($row['day'] ?? '')),
                'type' => 'webhook',
                'severity' => 'warning',
                'occurredAt' => $this->str($row['last_at'] ?? ''),
                'params' => [
                    'sourceName' => '' !== $this->str($row['profile_name'] ?? '') ? $this->str($row['profile_name'] ?? '') : $profileId,
                    'eventType' => $eventType,
                    'deliveries' => $this->intOf($row['deliveries'] ?? 0),
                    'httpStatus' => $this->intOf($row['http_status'] ?? 0),
                ],
                'context' => ['profileId' => $profileId],
            ];
        }

        return $items;
    }

    /**
     * A channel dropping below the publish threshold since yesterday's
     * snapshot (DASH-05 provides the reference point).
     *
     * @return list<AlertItem>
     */
    private function completenessDropAlerts(Tenant $tenant): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT per_channel FROM dashboard_snapshots '
            .'WHERE tenant_id = :tenant AND snapshot_date = CURRENT_DATE - 1',
            ['tenant' => $tenant->getId()->toRfc4122()],
        );
        if (false === $row) {
            return []; // no reference point yet — never fabricate a drop.
        }
        $rawPerChannel = $this->str($row['per_channel'] ?? '');
        $decoded = json_decode('' !== $rawPerChannel ? $rawPerChannel : '{}', true);
        $yesterday = \is_array($decoded) ? $decoded : [];

        $today = new DateTimeImmutable('today')->format('Y-m-d');
        $items = [];
        foreach ($this->channelCompleteness->perChannel(self::READY_THRESHOLD) as $channel) {
            $previous = $yesterday[$channel->channelCode] ?? null;
            $previousPct = \is_array($previous) ? $this->intOf($previous['avgPct'] ?? null) : null;
            if (null === $previousPct) {
                continue;
            }
            if ($channel->avgPct < self::READY_THRESHOLD && $previousPct >= self::READY_THRESHOLD) {
                $items[] = [
                    'fingerprint' => \sprintf('completeness_drop:%s:%s', $channel->channelCode, $today),
                    'type' => 'completeness_drop',
                    'severity' => 'warning',
                    'occurredAt' => $today,
                    'params' => [
                        'sourceName' => $channel->channelName,
                        'avgPct' => $channel->avgPct,
                        'previousPct' => $previousPct,
                        'threshold' => self::READY_THRESHOLD,
                    ],
                    'context' => ['channelCode' => $channel->channelCode],
                ];
            }
        }

        return $items;
    }

    /**
     * @param list<string> $fingerprints
     *
     * @return list<string>
     */
    private function ackedFingerprints(Tenant $tenant, array $fingerprints): array
    {
        if ([] === $fingerprints) {
            return [];
        }
        $rows = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT fingerprint FROM dashboard_alert_acks '
            .'WHERE tenant_id = :tenant AND fingerprint IN (:fingerprints)',
            [
                'tenant' => $tenant->getId()->toRfc4122(),
                'fingerprints' => $fingerprints,
            ],
            ['fingerprints' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        return array_values(array_filter($rows, 'is_string'));
    }

    private function currentTenant(): Tenant
    {
        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Cannot aggregate alerts without a current tenant.');
        }

        return $tenant;
    }

    private function intOf(mixed $value): ?int
    {
        if (null === $value) {
            return null;
        }

        return (int) (\is_scalar($value) ? $value : 0);
    }

    private function str(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
