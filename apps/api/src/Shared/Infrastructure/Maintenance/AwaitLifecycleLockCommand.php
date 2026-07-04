<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Maintenance;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Blocks until no database lifecycle operation (pim:db:reset) is in flight,
 * then exits. Container entrypoints call this BEFORE mutating shared state
 * that a running reset depends on.
 *
 * GOLIVE #2178 (fresh-clone proof, round 2) — the reset's FORCE drop kills
 * the worker's Messenger session; the restarted worker's entrypoint used to
 * run `cache:clear` on the SHARED /app/var volume immediately, deleting the
 * compiled DI container out from under the still-running reset CLI
 * ("getXxxService.php: Failed to open stream" on its final steps). Waiting
 * on the same cluster-wide advisory lock the reset holds for its entire run
 * serialises the two: the cache rebuild starts only after the reset is done.
 *
 * Best-effort by design: any failure (DB briefly unreachable during a boot
 * race) exits 0 with a warning — an entrypoint must never wedge the boot.
 */
#[AsCommand(
    name: 'pim:dev:await-lifecycle-lock',
    description: 'Wait until any in-flight database lifecycle operation (pim:db:reset) completes.',
)]
final class AwaitLifecycleLockCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $maintenance = MaintenanceConnectionFactory::fromConnection($this->connection);
            try {
                // tenant-safe: infrastructure (cluster-wide advisory lock on the maintenance DB; no tenant-scoped data)
                $maintenance->fetchOne(\sprintf('SELECT pg_advisory_lock(%d)', MaintenanceConnectionFactory::LIFECYCLE_LOCK_KEY));
            } finally {
                // Closing the session releases the lock — we only needed to
                // WAIT for the holder, not to hold it ourselves.
                $maintenance->close();
            }
        } catch (Throwable $e) {
            $io->warning(\sprintf('Could not check the lifecycle lock (%s) — continuing anyway.', $e->getMessage()));
        }

        return Command::SUCCESS;
    }
}
