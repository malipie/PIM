<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Command;

use App\Identity\Application\SuperAdmin\SuperAdminContext;
use App\Identity\Infrastructure\Provisioning\ProvisioningQueue;
use App\Shared\Domain\Repository\TenantRepositoryInterface;
use App\Shared\Infrastructure\Maintenance\TenantPurger;
use App\Shared\Infrastructure\Maintenance\TenantStoragePurgeException;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * RBAC-P5-021 (#711) — hard-deletes tenants whose soft-delete window
 * has expired (default 30 days). Designed to be invoked from cron or
 * Symfony Scheduler — daily cadence is enough since the grace period
 * is two orders of magnitude longer than the smallest cadence.
 *
 * Soft-delete semantics:
 *   - `tenants.status = 'deleted'` + `tenants.deleted_at = NOW()` set
 *     by the Super Admin operator via the API.
 *   - Recovery window is `--retention-days` (default 30).
 *   - After the window expires, this command hard-deletes the tenant
 *     and EVERY dependent row + object-storage prefix via
 *     {@see TenantPurger}
 *     (GDPR art. 17). NB: most FKs to `tenants` are ON DELETE RESTRICT,
 *     so a bare `DELETE FROM tenants` would fail — the purger deletes
 *     dependents in child→parent order inside a transaction (AUD-019),
 *     then `deleteDirectory(<tenant-uuid>)` per bucket (AUD-020).
 *
 * The lookup runs inside `SuperAdminContext::runCrossTenant()` so the
 * tenant filter doesn't hide soft-deleted rows from the candidate query.
 * `--dry-run` lists the candidate rows without touching them — safer
 * default for the first deployment cycle. Operators flip the flag off
 * once they've validated the candidates are correct.
 *
 * No platform-Super-Admin uuid is needed for the audit trail — the
 * command runs from cron (no human Super Admin), so the audit listener
 * picks up the CLI marker via `userAgent`.
 */
#[AsCommand(
    name: 'pim:tenants:purge-deleted',
    description: 'Hard-deletes tenants whose soft-delete window has expired (default 30 days).',
)]
final class PurgeDeletedTenantsCommand extends Command
{
    private const int DEFAULT_RETENTION_DAYS = 30;

    public function __construct(
        private readonly Connection $connection,
        private readonly SuperAdminContext $superAdminContext,
        private readonly TenantPurger $tenantPurger,
        private readonly TenantRepositoryInterface $tenants,
        private readonly ProvisioningQueue $provisioning,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'retention-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Days the soft-delete must have aged before hard-delete fires.',
                (string) self::DEFAULT_RETENTION_DAYS,
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'List the candidate tenants without deleting them.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $retention = (int) $input->getOption('retention-days');
        if ($retention < 1) {
            $io->error('`--retention-days` must be a positive integer.');

            return Command::FAILURE;
        }
        $dryRun = $input->getOption('dry-run') === true;

        // Run the lookup under cross-tenant mode so the tenant_filter
        // (active by default for SELECTs) doesn't hide candidates.
        // Using a synthetic Uuid is fine here — no audit row is
        // attributed to a specific human Super Admin for the scheduled
        // sweep; the audit trail for each hard-delete still records
        // `userAgent=cli:pim:tenants:purge-deleted`.
        $callerId = Uuid::v7();
        $rows = $this->superAdminContext->runCrossTenant(
            $callerId,
            fn (): array => $this->fetchExpiredCandidates($retention),
        );

        if ([] === $rows) {
            $io->success('No tenants past the retention window — nothing to purge.');

            return Command::SUCCESS;
        }

        $io->section(\sprintf('%d tenant(s) past the %d-day retention window:', \count($rows), $retention));
        foreach ($rows as $row) {
            $io->writeln(\sprintf(
                '  - %s (%s) deleted_at=%s',
                $row['code'],
                $row['id'],
                $row['deleted_at'],
            ));
        }

        if ($dryRun) {
            $io->note('Dry-run: no rows were modified. Re-run without --dry-run to hard-delete.');

            return Command::SUCCESS;
        }

        $deleted = 0;
        $storageFailures = 0;
        foreach ($rows as $row) {
            try {
                $uuid = Uuid::fromString($row['id']);
            } catch (InvalidArgumentException) {
                $io->warning(\sprintf('Skipping malformed tenant id `%s`.', $row['id']));
                continue;
            }

            // TNT-P4-08 (#2909) — okno odzyskania właśnie się zamknęło, więc
            // TERAZ, i tylko teraz, giną stack i wolumeny instancji. Zlecenie
            // idzie PRZED skasowaniem wiersza: po nim nie byłoby już z czego
            // odczytać kodu tenanta, a provisioner adresuje instancję kodem.
            // Skrypt usuwania i tak zaczyna od zrzutu końcowego, więc kolejność
            // „zlecenie → kasowanie wiersza" nie kasuje niczego w ciemno.
            $this->enqueuePurge($callerId, $uuid, $io);

            // The purger sets the RLS GUC to this exact tenant for its
            // deletes; running inside cross-tenant mode additionally drops
            // the Doctrine TenantFilter for any ORM read it may trigger.
            try {
                $this->superAdminContext->runCrossTenant(
                    $callerId,
                    fn (): int => $this->tenantPurger->purge($uuid),
                );
                ++$deleted;
            } catch (TenantStoragePurgeException $e) {
                // DB rows are gone (GDPR-compliant for the database), but
                // object-storage blobs may linger — surface it loudly so the
                // operator triggers a manual / GC sweep. Counts as deleted
                // for the DB, separately reported for storage.
                ++$deleted;
                ++$storageFailures;
                $io->warning(\sprintf(
                    'Tenant %s: DB purged but storage cleanup failed for bucket(s) %s. Blobs may linger.',
                    $row['code'],
                    implode(', ', $e->failedBuckets),
                ));
            }
        }

        if ($storageFailures > 0) {
            $io->warning(\sprintf(
                'Hard-deleted %d tenant(s); %d had storage-purge failures (see warnings above).',
                $deleted,
                $storageFailures,
            ));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Hard-deleted %d tenant(s).', $deleted));

        return Command::SUCCESS;
    }

    /**
     * Zleca zniszczenie stacku instancji. Porażka kolejki NIE przerywa
     * sprzątania bazy: obowiązek z art. 17 RODO dotyczy danych, a osierocone
     * kontenery są problemem operacyjnym, nie prawnym — trzeba je jednak
     * zobaczyć, stąd ostrzeżenie.
     */
    private function enqueuePurge(Uuid $callerId, Uuid $tenantId, SymfonyStyle $io): void
    {
        if (!$this->provisioning->isAvailable()) {
            return;
        }

        try {
            $this->superAdminContext->runCrossTenant($callerId, function () use ($tenantId, $callerId): void {
                $tenant = $this->tenants->findById($tenantId);
                if (null !== $tenant) {
                    $this->provisioning->enqueueLifecycle($tenant, 'purge', $callerId);
                }
            });
        } catch (RuntimeException $exception) {
            $io->warning(\sprintf(
                'Nie udało się zlecić usunięcia instancji %s: %s. Stack i wolumeny zostają — usuń je ręcznie skryptem pim-tenant-remove.sh.',
                $tenantId->toRfc4122(),
                $exception->getMessage(),
            ));
        }
    }

    /**
     * @return list<array{id: string, code: string, deleted_at: string}>
     */
    private function fetchExpiredCandidates(int $retentionDays): array
    {
        $sql = <<<'SQL'
            SELECT id, code, deleted_at::text AS deleted_at
            FROM tenants
            WHERE status = 'deleted'
              AND deleted_at IS NOT NULL
              AND deleted_at < (NOW() - (:retention_days || ' days')::interval)
            ORDER BY deleted_at ASC
            SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'retention_days' => (string) $retentionDays,
        ]);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => \is_string($row['id']) ? $row['id'] : '',
                'code' => \is_string($row['code']) ? $row['code'] : '',
                'deleted_at' => \is_string($row['deleted_at']) ? $row['deleted_at'] : '',
            ];
        }

        return $out;
    }
}
