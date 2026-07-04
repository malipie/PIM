<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Maintenance;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Idempotent dev-database seed guard. Run on every container start
 * (wired through docker-entrypoint.sh) so an empty database — created by
 * `pim:db:reset`, an aborted migration, or a fresh `docker compose down -v`
 * — is automatically backfilled before the operator hits the login form
 * and sees a misleading "invalid credentials" error.
 *
 * Decision tree:
 *   - APP_ENV != dev/test → exit 0 (silently noop, never touch prod data).
 *   - DB is unreachable → exit 0 with warning (compose `depends_on:
 *     database: service_healthy` should prevent this; the warning lane
 *     handles transient races without breaking the boot).
 *   - `users` table missing OR `users` table empty → run
 *     `pim:db:reset --with-fixtures --force` (covers post-
 *     `docker compose down -v` and post-`pim:db:reset` cases — both
 *     leave the database with no application data to lose).
 *   - `users` table has rows but admin@demo.localhost missing → warn
 *     and exit 0 (do NOT auto-mutate a populated DB; running fixtures
 *     `--append` would crash on unique constraints, and a destructive
 *     reset is the operator's call to make).
 *   - admin@demo.localhost present → exit 0 ("already seeded").
 *
 * Designed to be safe to call ANY number of times. The `--quiet` flag
 * suppresses output when nothing changes so container logs stay clean.
 */
#[AsCommand(
    name: 'pim:dev:ensure-seeded',
    description: 'Idempotent seed guard for dev — auto-loads fixtures if admin user is missing.',
)]
final class EnsureSeededCommand extends Command
{
    private const string ADMIN_EMAIL = 'admin@demo.localhost';

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'quiet-when-noop',
            null,
            InputOption::VALUE_NONE,
            'Suppress output when seeding is not needed (entrypoint use).',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $envRaw = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev';
        $env = \is_string($envRaw) ? $envRaw : 'dev';
        // VALUE_NONE options are typed as bool by phpstan-symfony — no cast needed.
        $quiet = $input->getOption('quiet-when-noop');

        if ('dev' !== $env && 'test' !== $env) {
            if (!$quiet) {
                $io->note(\sprintf('Skipping ensure-seeded: APP_ENV=%s (only runs on dev/test).', $env));
            }

            return Command::SUCCESS;
        }

        $usersTableExists = $this->tableExists('users');
        $userCount = $usersTableExists ? $this->userCount() : 0;

        if ($usersTableExists && $userCount > 0 && $this->adminUserExists()) {
            if (!$quiet) {
                $io->success('Database already seeded — admin user present.');
            }

            return Command::SUCCESS;
        }

        $application = $this->getApplication();
        if (null === $application) {
            $io->error('Console application not available — cannot chain commands.');

            return Command::FAILURE;
        }

        if ($usersTableExists && $userCount > 0) {
            $io->warning(\sprintf(
                'Database has %d user(s) but admin@demo.localhost is missing. '
                .'Refusing to auto-seed a populated DB — run '
                ."'docker compose exec api bin/console pim:db:reset --with-fixtures --force' "
                .'manually if a full reset is intended.',
                $userCount,
            ));

            return Command::SUCCESS;
        }

        // GOLIVE #2178 follow-up — before resetting, wait for any concurrent
        // lifecycle operation to finish. `pim:db:reset` FORCE-drops the
        // database, which kills this container's Messenger consumer; the
        // restart re-runs this command, which would otherwise see the
        // half-reset (empty) database and chain a SECOND reset that kills the
        // first one's fixtures load. The advisory lock lives on the
        // maintenance DB (cluster-wide, survives the drop): block until the
        // concurrent reset completes, then re-check — if it just seeded the
        // database, there is nothing left to do.
        try {
            $maintenance = MaintenanceConnectionFactory::fromConnection($this->connection);
            try {
                // tenant-safe: infrastructure (cluster-wide advisory lock on the maintenance DB; no tenant-scoped data)
                $maintenance->fetchOne(\sprintf('SELECT pg_advisory_lock(%d)', MaintenanceConnectionFactory::LIFECYCLE_LOCK_KEY));
            } finally {
                // Closing the session releases the lock immediately — we only
                // needed to WAIT, not to hold it (the chained reset below
                // re-acquires it on its own session; holding ours would
                // deadlock the child).
                $maintenance->close();
            }
        } catch (DbalException) {
            // Lock acquisition is best-effort: without it we simply fall back
            // to the pre-lock behaviour (re-check + reset).
        }

        // A session opened before a concurrent FORCE drop is dead — discard
        // it so the re-check below connects fresh against the new database.
        $this->connection->close();

        if ($this->tableExists('users') && $this->userCount() > 0 && $this->adminUserExists()) {
            if (!$quiet) {
                $io->success('Database was seeded by a concurrent reset while we waited — nothing to do.');
            }

            return Command::SUCCESS;
        }

        $io->section('Empty database — running pim:db:reset --with-fixtures');

        // Release our DBAL connection so the drop chained by pim:db:reset is
        // not blocked by our own session in postgres.
        $this->connection->close();

        $resetInput = new ArrayInput([
            '--force' => true,
            '--with-fixtures' => true,
        ]);
        // Match DatabaseResetCommand's nested-call behaviour: must be
        // non-interactive so the chained pim:db:reset honours --force without
        // prompting (the entrypoint runs with no TTY).
        $resetInput->setInteractive(false);

        $exitCode = $application
            ->find('pim:db:reset')
            ->run($resetInput, $output);

        if (Command::SUCCESS !== $exitCode) {
            $io->error(\sprintf('pim:db:reset failed (exit %d).', $exitCode));

            return Command::FAILURE;
        }

        $io->success('Schema created and fixtures loaded.');

        return Command::SUCCESS;
    }

    private function userCount(): int
    {
        try {
            $count = $this->connection->fetchOne('SELECT COUNT(*) FROM users');
        } catch (DbalException) {
            return 0;
        }

        return \is_numeric($count) ? (int) $count : 0;
    }

    private function tableExists(string $name): bool
    {
        try {
            return $this->connection->createSchemaManager()->tablesExist([$name]);
        } catch (DbalException) {
            return false;
        }
    }

    private function adminUserExists(): bool
    {
        try {
            $count = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM users WHERE email = :email',
                ['email' => self::ADMIN_EMAIL],
            );
        } catch (DbalException) {
            return false;
        }

        return \is_numeric($count) && (int) $count > 0;
    }
}
