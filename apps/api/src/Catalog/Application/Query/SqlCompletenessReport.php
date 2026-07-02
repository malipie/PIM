<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Contracts\Query\CompletenessReport;
use App\Catalog\Contracts\Query\CompletenessReportPort;
use App\Catalog\Domain\Entity\ObjectType;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;

/**
 * AGENT-P2-03 (#1960) — SQL aggregation over the existing completeness
 * scoring (objects.completeness_pct is maintained by
 * AttributesIndexedRebuilder; this port only READS): totals, average,
 * below-threshold count, and per-required-attribute missing counts via
 * jsonb_exists on attributes_indexed.
 *
 * Approximation, documented: presence in the GLOBAL attributes_indexed
 * reading (locale/channel overlays don't count) — the same basis the
 * `global` completeness percentage uses.
 */
final readonly class SqlCompletenessReport implements CompletenessReportPort
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
    ) {
    }

    public function report(string $objectTypeCode, int $thresholdPct = 90): CompletenessReport
    {
        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Cannot build a completeness report without a current tenant.');
        }
        $tenantId = $tenant->getId()->toRfc4122();

        $objectType = $this->entityManager->getRepository(ObjectType::class)
            ->findOneBy(['code' => $objectTypeCode, 'tenant' => $tenant]);
        if (!$objectType instanceof ObjectType) {
            throw new InvalidArgumentException(\sprintf('Unknown object type "%s".', $objectTypeCode));
        }

        $rules = $objectType->getCompletenessRules();
        $requiredCodes = $this->stringList($rules['required'] ?? null);

        $connection = $this->entityManager->getConnection();

        // tenant-safe: explicit tenant_id predicate on every aggregate below
        // (raw SQL bypasses the Doctrine TenantFilter); RLS is the backstop.
        $row = $connection->fetchAssociative(
            'SELECT COUNT(*) AS total, COALESCE(AVG(completeness_pct), 0) AS avg_pct, '
            .'COUNT(*) FILTER (WHERE completeness_pct < :threshold) AS below '
            .'FROM objects WHERE tenant_id = :tenant AND object_type_id = :object_type',
            [
                'threshold' => $thresholdPct,
                'tenant' => $tenantId,
                'object_type' => $objectType->getId()->toRfc4122(),
            ],
        );

        $totalRaw = $row['total'] ?? 0;
        $avgRaw = $row['avg_pct'] ?? 0;
        $belowRaw = $row['below'] ?? 0;
        $total = (int) (\is_scalar($totalRaw) ? $totalRaw : 0);
        $avgPct = (int) round(\is_numeric($avgRaw) ? (float) $avgRaw : 0.0);
        $below = (int) (\is_scalar($belowRaw) ? $belowRaw : 0);

        $missing = [];
        foreach ($requiredCodes as $code) {
            // tenant-safe: explicit tenant_id predicate (see above).
            $missingCount = $connection->fetchOne(
                'SELECT COUNT(*) FROM objects '
                .'WHERE tenant_id = :tenant AND object_type_id = :object_type '
                .'AND NOT jsonb_exists(attributes_indexed, :code)',
                [
                    'tenant' => $tenantId,
                    'object_type' => $objectType->getId()->toRfc4122(),
                    'code' => $code,
                ],
            );
            $count = (int) (\is_scalar($missingCount) ? $missingCount : 0);
            if ($count > 0) {
                $missing[] = ['code' => $code, 'missing_count' => $count];
            }
        }
        usort($missing, static fn (array $a, array $b): int => $b['missing_count'] <=> $a['missing_count']);

        return new CompletenessReport(
            objectTypeCode: $objectTypeCode,
            totalObjects: $total,
            averagePct: $avgPct,
            belowThresholdCount: $below,
            thresholdPct: $thresholdPct,
            missingRequired: $missing,
        );
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
