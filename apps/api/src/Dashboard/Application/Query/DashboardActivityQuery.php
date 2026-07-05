<?php

declare(strict_types=1);

namespace App\Dashboard\Application\Query;

use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;

/**
 * DASH-07 (#2261, ADR-0026) — team-activity aggregates for the dashboard:
 *
 *   - `added` per day comes from `objects.created_at` (exact),
 *   - `modified` per day and the top-edited ranking come from `audit_logs`
 *     (one row per HTTP request; RBAC-P3-013 listener) narrowed to granted
 *     PATCH/PUT hits on the product-edit route allowlist below.
 *
 * Documented undercount: bulk edits, imports and agent batch-accepts are
 * single requests, so they weigh 1 regardless of how many objects they
 * touch — acceptable for a team-pace proxy (the exact per-object audit
 * trail is a dh-auditor follow-up; it deliberately does not track
 * CatalogObject yet).
 */
final readonly class DashboardActivityQuery
{
    public const array RANGES = ['7d' => 7, '30d' => 30, '90d' => 90];

    /**
     * `_route` names of per-object product writes (see `debug:router`).
     * Schema routes (object_types) are deliberately absent — reshaping an
     * attribute is modeling work, not catalog-data pace.
     */
    private const array EDIT_ROUTES = [
        'products_patch',
        'objects_patch',
        'pim_products_locks_replace',
        'pim_objects_categories_replace',
        'pim_products_categories_replace',
        'pim_objects_relations_put',
        'pim_products_channel_placements_put',
        'pim_objects_channel_placements_put',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
    ) {
    }

    /**
     * Contiguous daily series (gap-filled zeroes, oldest → newest).
     *
     * @return array<string, mixed>
     */
    public function activity(string $range): array
    {
        $days = self::RANGES[$range] ?? null;
        if (null === $days) {
            throw new InvalidArgumentException(\sprintf('Unknown range "%s".', $range));
        }
        $tenantId = $this->tenantId();
        $connection = $this->entityManager->getConnection();
        $from = new DateTimeImmutable('today')->modify(\sprintf('-%d days', $days - 1));

        // tenant-safe: explicit tenant_id predicates (raw SQL bypasses the
        // Doctrine TenantFilter); RLS is the backstop.
        $addedRows = $connection->fetchAllKeyValue(
            'SELECT created_at::date AS day, COUNT(*) FROM objects '
            ."WHERE tenant_id = :tenant AND kind = 'product' AND created_at >= :from "
            .'GROUP BY 1',
            ['tenant' => $tenantId, 'from' => $from->format('Y-m-d')],
        );
        $modifiedRows = $connection->fetchAllKeyValue(
            'SELECT created_at::date AS day, COUNT(*) FROM audit_logs '
            .'WHERE tenant_id = :tenant AND created_at >= :from '
            ."AND action IN ('PATCH', 'PUT') AND permission_check_result = 'granted' "
            .'AND resource_type IN (:routes) '
            .'GROUP BY 1',
            ['tenant' => $tenantId, 'from' => $from->format('Y-m-d'), 'routes' => self::EDIT_ROUTES],
            ['routes' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        $series = [];
        $addedTotal = 0;
        $modifiedTotal = 0;
        for ($i = 0; $i < $days; ++$i) {
            $date = $from->modify(\sprintf('+%d days', $i))->format('Y-m-d');
            $added = $this->intOf($addedRows[$date] ?? 0);
            $modified = $this->intOf($modifiedRows[$date] ?? 0);
            $series[] = ['date' => $date, 'added' => $added, 'modified' => $modified];
            $addedTotal += $added;
            $modifiedTotal += $modified;
        }

        return [
            'range' => $range,
            'series' => $series,
            'addedTotal' => $addedTotal,
            'modifiedTotal' => $modifiedTotal,
            'avgPerDay' => (int) round(($addedTotal + $modifiedTotal) / $days),
        ];
    }

    /**
     * Most-edited products in the window: audit_logs ranking hydrated from
     * `objects` (deleted products drop out on the join — hence the ×2
     * over-fetch before trimming to the requested limit).
     *
     * @return array<string, mixed>
     */
    public function topEdited(string $range, int $limit): array
    {
        $days = self::RANGES[$range] ?? null;
        if (null === $days) {
            throw new InvalidArgumentException(\sprintf('Unknown range "%s".', $range));
        }
        $tenantId = $this->tenantId();
        $connection = $this->entityManager->getConnection();
        $from = new DateTimeImmutable('today')->modify(\sprintf('-%d days', $days - 1));

        // tenant-safe: explicit tenant_id predicates (see activity()).
        $ranking = $connection->fetchAllKeyValue(
            'SELECT resource_id, COUNT(*) AS edits FROM audit_logs '
            .'WHERE tenant_id = :tenant AND created_at >= :from '
            ."AND action IN ('PATCH', 'PUT') AND permission_check_result = 'granted' "
            .'AND resource_type IN (:routes) AND resource_id IS NOT NULL '
            .'GROUP BY resource_id ORDER BY edits DESC, resource_id ASC LIMIT :limit',
            [
                'tenant' => $tenantId,
                'from' => $from->format('Y-m-d'),
                'routes' => self::EDIT_ROUTES,
                'limit' => $limit * 2,
            ],
            ['routes' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        if ([] === $ranking) {
            return ['items' => []];
        }

        $objects = $connection->fetchAllAssociative(
            'SELECT id, code, completeness_pct, attributes_indexed FROM objects '
            ."WHERE tenant_id = :tenant AND kind = 'product' AND id IN (:ids)",
            ['tenant' => $tenantId, 'ids' => array_keys($ranking)],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $byId = [];
        foreach ($objects as $row) {
            $id = \is_string($row['id'] ?? null) ? $row['id'] : '';
            if ('' !== $id) {
                $byId[$id] = $row;
            }
        }

        $items = [];
        foreach ($ranking as $resourceId => $edits) {
            $row = $byId[(string) $resourceId] ?? null;
            if (null === $row) {
                continue; // deleted or non-product resource — skip.
            }
            $code = \is_string($row['code'] ?? null) ? $row['code'] : '';
            $items[] = [
                'id' => (string) $resourceId,
                'name' => $this->displayName($row['attributes_indexed'] ?? null, $code),
                'sku' => $code,
                'completenessPct' => $this->intOf($row['completeness_pct'] ?? 0),
                'edits' => $this->intOf($edits),
            ];
            if (\count($items) >= $limit) {
                break;
            }
        }

        return ['items' => $items];
    }

    /**
     * Defensive display-name extraction from the `attributes_indexed`
     * envelope ({@link docs/api/jsonb-schemas.md}): plain string value,
     * per-locale map (first non-empty wins), or the code fallback.
     */
    private function displayName(mixed $indexedJson, string $fallback): string
    {
        $indexed = \is_string($indexedJson)
            ? json_decode($indexedJson, true)
            : $indexedJson;
        if (!\is_array($indexed)) {
            return $fallback;
        }
        $name = $indexed['name'] ?? null;
        if (!\is_array($name)) {
            return $fallback;
        }
        $value = $name['value'] ?? null;
        if (\is_string($value) && '' !== $value) {
            return $value;
        }
        if (\is_array($value)) {
            foreach ($value as $candidate) {
                if (\is_string($candidate) && '' !== $candidate) {
                    return $candidate;
                }
            }
        }

        return $fallback;
    }

    private function tenantId(): string
    {
        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Cannot aggregate dashboard activity without a current tenant.');
        }

        return $tenant->getId()->toRfc4122();
    }

    private function intOf(mixed $value): int
    {
        return (int) (\is_scalar($value) ? $value : 0);
    }
}
