<?php

declare(strict_types=1);

namespace App\Export\Presentation\Command;

use App\Export\Domain\Repository\ExportSessionRepositoryInterface;
use App\Shared\Domain\Repository\TenantRepositoryInterface;
use App\Shared\Infrastructure\Tenant\TenantScopeBinder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * AUD-050 (W2-11) — enforces export retention for compliance (GDPR / RODO).
 *
 * Generated exports carry full product data and PII. Paid tiers keep them
 * forever (PRD §11.7); the free tier (`starter`) must NOT accumulate PII
 * indefinitely, so this command deletes free-tier `export_sessions` (DB row +
 * the MinIO file under `<tenant_id>/<session_id>.<format>`) whose `started_at`
 * is older than the retention window (env `EXPORT_FREE_RETENTION_DAYS`,
 * default 7). Until this landed, the only cleanup note in flysystem.yaml was a
 * TODO ("lands with scheduled command") and exports grew without bound.
 *
 * Tenant-scoped by design — it iterates every tenant and sets the tenant
 * context per tenant so the (RLS-protected, once FORCE RLS lands) query +
 * cleanup never reach across tenant boundaries, mirroring
 * {@see \App\Import\Presentation\Command\PurgeStagedFilesCommand}. Paid tenants
 * are skipped entirely. Intended for a daily scheduler entry (AUD-051).
 *
 * Memory (FrankenPHP worker mode, §3.10): `findOlderThan` caps the per-tenant
 * batch and the EntityManager is cleared after each tenant's flush, so the
 * Identity Map stays bounded even across many tenants.
 */
#[AsCommand(
    name: 'pim:exports:cleanup',
    description: 'Delete free-tier exports (DB row + file) past the retention window. Paid tiers keep exports forever.',
)]
final class CleanupExportsCommand extends Command
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly ExportSessionRepositoryInterface $sessions,
        private readonly TenantScopeBinder $tenantScope,
        private readonly FilesystemOperator $exportsStorage,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $freeRetentionDays,
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
                'Override the free-tier retention window in days (default: env EXPORT_FREE_RETENTION_DAYS).',
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be deleted without touching anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = true === $input->getOption('dry-run');

        $override = $input->getOption('retention-days');
        $days = null === $override ? $this->freeRetentionDays : (int) $override;
        if ($days < 1) {
            $io->error('Retention window must be a positive integer (days).');

            return Command::INVALID;
        }

        $cutoff = new DateTimeImmutable(\sprintf('-%d days', $days));
        $io->title(\sprintf(
            'Cleanup free-tier exports older than %dd (cutoff %s)%s',
            $days,
            $cutoff->format('c'),
            $dryRun ? ' — DRY RUN' : '',
        ));

        $deleted = 0;
        $fileFailures = 0;
        $skippedPaid = 0;
        foreach ($this->tenants->findAllOrderedByCode() as $tenant) {
            if (!$tenant->isFreeTier()) {
                ++$skippedPaid;
                continue;
            }

            $this->tenantScope->bind($tenant);
            $stale = $this->sessions->findOlderThan($tenant, $cutoff);
            $removedThisTenant = false;
            foreach ($stale as $session) {
                $io->writeln(\sprintf(
                    '  [%s] session %s (started %s, file %s)',
                    $tenant->getCode(),
                    $session->getId()->toRfc4122(),
                    $session->getStartedAt()->format('c'),
                    $session->getFilePath() ?? '—',
                ));
                if ($dryRun) {
                    ++$deleted;
                    continue;
                }

                $filePath = $session->getFilePath();
                if (null !== $filePath && '' !== $filePath) {
                    try {
                        $this->exportsStorage->delete($filePath);
                    } catch (FilesystemException $exception) {
                        // File already gone (manual cleanup / failed write): still
                        // drop the row so the table does not keep a dangling ref.
                        $io->warning(\sprintf('Storage delete failed for %s: %s', $filePath, $exception->getMessage()));
                        ++$fileFailures;
                    }
                }
                // Batch the row deletes: one flush per tenant instead of one per
                // session (findOlderThan caps the batch, so memory stays bounded).
                $this->entityManager->remove($session);
                $removedThisTenant = true;
                ++$deleted;
            }
            if ($removedThisTenant) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }

        $io->success(\sprintf(
            '%s %d free-tier export(s)%s; skipped %d paid tenant(s) (forever retention).',
            $dryRun ? 'Would delete' : 'Deleted',
            $deleted,
            $fileFailures > 0 ? \sprintf(' (%d with storage warnings)', $fileFailures) : '',
            $skippedPaid,
        ));

        return Command::SUCCESS;
    }
}
