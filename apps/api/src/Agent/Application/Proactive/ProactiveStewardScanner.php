<?php

declare(strict_types=1);

namespace App\Agent\Application\Proactive;

use App\Agent\Application\AgentFeatureGuard;
use App\Agent\Application\Limits\AgentLimitGuard;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentMessage;
use App\Agent\Domain\Entity\AgentRun;
use App\Catalog\Contracts\Query\CompletenessReportPort;
use App\Identity\Contracts\Byok\ByokConfigReaderInterface;
use App\Shared\Domain\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P8-01 (#1983) — the proactive data steward (Fala 4): without a
 * prompt, scan a tenant for
 *
 *   - GAPS: required attributes missing on many objects (the same
 *     completeness engine the on-demand tool uses, P2-03), and
 *   - ANOMALIES: numeric values wildly above their attribute's median
 *     (100x per PRD §2.3 - a 100 000 price among 100s), computed
 *     tenant-safe over the canonical object_values;
 *
 * findings open a run (surface=proactive, awaiting_input) with an
 * assistant report - the steward reads it in the chat/history UI and
 * decides what to do; NOTHING commits without the usual approval gate.
 * Strictly opt-in (tenant_agent_configs.proactive_scan_enabled) and
 * subject to the §8.5 budgets like every other run.
 */
final readonly class ProactiveStewardScanner
{
    private const int GAP_THRESHOLD_PCT = 90;
    private const int MAX_REPORTED_FINDINGS = 10;
    private const int ANOMALY_FACTOR = 100;

    public function __construct(
        private AgentFeatureGuard $featureGuard,
        private ByokConfigReaderInterface $byokConfig,
        private AgentLimitGuard $limits,
        private CompletenessReportPort $completeness,
        private Connection $connection,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return AgentRun|null the opened report run, or null when disabled /
     *                       nothing found
     */
    public function scanTenant(Tenant $tenant, Uuid $stewardUserId, string $objectTypeCode = 'product'): ?AgentRun
    {
        if (!$this->featureGuard->isEnabled($tenant) || !$this->byokConfig->isProactiveScanEnabled($tenant)) {
            return null;
        }

        // Proactivity does not bypass the budgets (§8.5).
        $this->limits->assertWithinLimits($tenant, $stewardUserId);

        $findings = [];

        $report = $this->completeness->report($objectTypeCode, self::GAP_THRESHOLD_PCT);
        foreach (\array_slice($report->missingRequired, 0, self::MAX_REPORTED_FINDINGS) as $gap) {
            if ($gap['missing_count'] > 0) {
                $findings[] = \sprintf('Luka: atrybut "%s" nie ma wartości w %d obiektach.', $gap['code'], $gap['missing_count']);
            }
        }

        foreach ($this->numericAnomalies($tenant) as $anomaly) {
            $findings[] = \sprintf(
                'Anomalia: atrybut "%s" ma %d wartości ponad %d× mediany (mediana %.2f, maks. %.2f).',
                $anomaly['code'],
                $anomaly['outliers'],
                self::ANOMALY_FACTOR,
                $anomaly['median'],
                $anomaly['max'],
            );
        }

        if ([] === $findings) {
            return null;
        }

        $run = new AgentRun($stewardUserId, AgentRunSurface::Proactive, 'Proaktywny skan danych (anomalie i luki)');
        $run->markAwaitingInput();
        $this->entityManager->persist($run);

        $reportText = "Proaktywny skan wykrył:\n- ".implode("\n- ", $findings)
            ."\n\nPowiedz, czym mam się zająć - każdy zapis i tak przejdzie przez Twoją akceptację.";
        $this->entityManager->persist(new AgentMessage($run, AgentMessage::ROLE_ASSISTANT, [
            ['type' => 'text', 'text' => $reportText],
        ]));
        $this->entityManager->flush();

        return $run;
    }

    /**
     * Numeric outliers per number-type attribute: values above
     * ANOMALY_FACTOR x the attribute's median. tenant-safe: explicit
     * tenant_id predicates (raw SQL bypasses the Doctrine TenantFilter);
     * RLS is the backstop.
     *
     * @return list<array{code: string, outliers: int, median: float, max: float}>
     */
    private function numericAnomalies(Tenant $tenant): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                WITH numeric_values AS (
                    SELECT a.code, (ov.value->>'value')::numeric AS num
                      FROM object_values ov
                      JOIN attributes a ON a.id = ov.attribute_id
                     WHERE ov.tenant_id = :tenant
                       AND a.tenant_id = :tenant
                       AND a.type IN ('number', 'price', 'metric')
                       AND jsonb_typeof(ov.value->'value') = 'number'
                ),
                medians AS (
                    SELECT code, percentile_cont(0.5) WITHIN GROUP (ORDER BY num) AS median
                      FROM numeric_values
                     GROUP BY code
                    HAVING COUNT(*) >= 3
                )
                SELECT nv.code,
                       COUNT(*) FILTER (WHERE nv.num > m.median * :factor) AS outliers,
                       m.median,
                       MAX(nv.num) AS max
                  FROM numeric_values nv
                  JOIN medians m ON m.code = nv.code AND m.median > 0
                 GROUP BY nv.code, m.median
                HAVING COUNT(*) FILTER (WHERE nv.num > m.median * :factor) > 0
                 ORDER BY outliers DESC
                 LIMIT 10
                SQL,
            ['tenant' => $tenant->getId()->toRfc4122(), 'factor' => self::ANOMALY_FACTOR],
        );

        $anomalies = [];
        foreach ($rows as $row) {
            if (!\is_string($row['code'])) {
                continue;
            }
            $anomalies[] = [
                'code' => $row['code'],
                'outliers' => \is_numeric($row['outliers']) ? (int) $row['outliers'] : 0,
                'median' => \is_numeric($row['median']) ? (float) $row['median'] : 0.0,
                'max' => \is_numeric($row['max']) ? (float) $row['max'] : 0.0,
            ];
        }

        return $anomalies;
    }
}
