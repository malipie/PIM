<?php

declare(strict_types=1);

namespace App\Import\Presentation\Command;

use App\Import\Domain\Repository\ImportUndoLogRepositoryInterface;
use App\Import\Domain\Repository\StagedFileRepositoryInterface;
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
 * IMP2-2.2 — TTL sweep for staged uploads: deletes `import_staged_files`
 * rows + their MinIO objects older than 24h. A staged file only matters
 * during a single wizard run; anything still around a day later is
 * abandoned (closed tab, never confirmed).
 *
 * Tenant-scoped by design: it iterates tenants and sets the tenant context
 * per tenant so the RLS-protected query + cleanup never reach across tenant
 * boundaries. Intended for a daily scheduler/cron entry.
 */
#[AsCommand(
    name: 'pim:import:purge-staged',
    description: 'Delete staged import uploads (rows + MinIO objects) older than the TTL (24h).',
)]
final class PurgeStagedFilesCommand extends Command
{
    private const int TTL_HOURS = 24;

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly StagedFileRepositoryInterface $stagedFiles,
        private readonly ImportUndoLogRepositoryInterface $undoLog,
        private readonly FilesystemOperator $importsStorage,
        private readonly TenantScopeBinder $tenantScope,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be deleted without touching anything.');
        $this->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Override the TTL in hours.', (string) self::TTL_HOURS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = true === $input->getOption('dry-run');
        $hours = max(1, (int) $input->getOption('hours'));
        $cutoff = new DateTimeImmutable(\sprintf('-%d hours', $hours));

        $io->title(\sprintf('Purge staged uploads older than %dh (cutoff %s)%s', $hours, $cutoff->format('c'), $dryRun ? ' — DRY RUN' : ''));

        $deleted = 0;
        $failed = 0;
        $undoPurged = 0;
        foreach ($this->tenants->findAllOrderedByCode() as $tenant) {
            $this->tenantScope->bind($tenant);
            $expired = $this->stagedFiles->findExpired($tenant, $cutoff);
            $removedThisTenant = false;
            foreach ($expired as $stagedFile) {
                $io->writeln(\sprintf(
                    '  [%s] %s (%s, %s)',
                    $tenant->getCode(),
                    $stagedFile->getStorageKey(),
                    $stagedFile->getFileName(),
                    $stagedFile->getCreatedAt()->format('c'),
                ));
                if ($dryRun) {
                    ++$deleted;

                    continue;
                }
                try {
                    $this->importsStorage->delete($stagedFile->getStorageKey());
                } catch (FilesystemException $exception) {
                    // Object already gone (manual cleanup / failed upload): still
                    // drop the row so the table does not accumulate dangling refs.
                    $io->warning(\sprintf('Storage delete failed for %s: %s', $stagedFile->getStorageKey(), $exception->getMessage()));
                    ++$failed;
                }
                // Batch the row deletes: one flush per tenant instead of one per
                // file (findExpired caps the batch, so memory stays bounded).
                $this->entityManager->remove($stagedFile);
                $removedThisTenant = true;
                ++$deleted;
            }
            if ($removedThisTenant) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }

            // IMP2-2.4 (spec §6) — purge undo-log of sessions whose rollback
            // window has closed (tenant-scoped via the context set above).
            if (!$dryRun) {
                $undoPurged += $this->undoLog->purgeForClosedWindows(new DateTimeImmutable());
            }
        }

        $io->success(\sprintf(
            '%s %d staged upload(s)%s; purged %d closed-window undo-log row(s).',
            $dryRun ? 'Would delete' : 'Deleted',
            $deleted,
            $failed > 0 ? \sprintf(' (%d with storage warnings)', $failed) : '',
            $undoPurged,
        ));

        return Command::SUCCESS;
    }
}
