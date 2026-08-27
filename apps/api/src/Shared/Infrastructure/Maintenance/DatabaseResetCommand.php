<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Maintenance;

use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;
use Throwable;

use const PHP_BINARY;

/**
 * Drop + create + migrate + (optional) load fixtures, in a single command.
 *
 * The Sprint-0 dance was three separate `bin/console` calls plus stopping the
 * api container so FrankenPHP would release its persistent connection. This
 * command bundles the SQL side of that workflow; since GOLIVE #2178 the drop
 * uses DROP DATABASE … WITH (FORCE), so lingering api/worker sessions no
 * longer require stopping containers first, and the whole run self-elevates
 * to the owner connection (see execute()) so it works under the W1-1
 * runtime role without env-var tricks.
 *
 * Designed for development databases. In production a refusal trips on
 * APP_ENV=prod unless `--force-prod` is passed, so a stray invocation does not
 * delete a pilot tenant's data.
 */
#[AsCommand(
    name: 'pim:db:reset',
    description: 'Drop + create + migrate (+ fixtures) the development database in one shot.'
)]
final class DatabaseResetCommand extends Command
{
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        // Only getParams() is read (never connects) — the drop itself runs on
        // a throwaway maintenance-DB connection derived from these params.
        #[Autowire(service: 'doctrine.dbal.owner_connection')]
        private readonly Connection $ownerConnection,
        // Closed right after the FORCE drop: a console-boot listener may have
        // already opened it, and a session killed by DROP … WITH (FORCE)
        // surfaces as "terminating connection due to administrator command"
        // on next reuse (observed on chained post-create console steps).
        // close() discards the dead socket; DBAL reconnects lazily.
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $defaultConnection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('with-fixtures', null, InputOption::VALUE_NONE, 'Load Doctrine fixtures after migration')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip the interactive confirmation prompt')
            ->addOption('force-prod', null, InputOption::VALUE_NONE, 'Allow running against APP_ENV=prod (dangerous)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        // PHPStan max: $_SERVER values are mixed; narrow before any sprintf.
        $envRaw = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev';
        $env = \is_string($envRaw) ? $envRaw : 'dev';

        if ('prod' === $env && !$input->getOption('force-prod')) {
            $io->error('Refusing to reset the production database. Pass --force-prod to override.');

            return Command::FAILURE;
        }

        if (!$input->getOption('force')) {
            $question = new ConfirmationQuestion(
                sprintf('This drops the <comment>%s</> database. Continue? (y/N) ', $env),
                false
            );
            // SymfonyStyle::askQuestion returns mixed; ConfirmationQuestion
            // resolves to bool, but PHPStan max won't trust that without an
            // explicit comparison.
            if (true !== $io->askQuestion($question)) {
                $io->warning('Aborted.');

                return Command::FAILURE;
            }
        }

        // VALUE_NONE options are typed as bool by phpstan-symfony — no cast.
        $loadFixtures = $input->getOption('with-fixtures');

        // GOLIVE #2178 (cold-start L5/L6/L8) — since the W1-1 role split the
        // default connection is `pim_app` (non-owner, NOBYPASSRLS): database
        // drop/create need the table-owner role, and
        // fixtures/reindex need BYPASSRLS (a CLI run never sets the per-request
        // tenant GUC, so FORCE RLS would deny every insert / hide every row).
        // The entrypoint seed already works around this by swapping
        // DATABASE_URL to DATABASE_URL_OWNER; formalise the same swap here by
        // re-executing this command in a child process on the owner DSN, so
        // the documented `pim:db:reset` invocation works without tribal
        // env-var knowledge. Single-role setups (owner fallback equals the
        // runtime DSN — see .env) skip the hop entirely.
        $ownerDsnRaw = $_SERVER['DATABASE_URL_OWNER'] ?? $_ENV['DATABASE_URL_OWNER'] ?? null;
        $currentDsnRaw = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null;
        $ownerDsn = \is_string($ownerDsnRaw) ? $ownerDsnRaw : '';
        $currentDsn = \is_string($currentDsnRaw) ? $currentDsnRaw : '';
        $elevated = '1' === ($_SERVER['PIM_DB_RESET_ELEVATED'] ?? $_ENV['PIM_DB_RESET_ELEVATED'] ?? '');

        if (!$elevated && '' !== $ownerDsn && $ownerDsn !== $currentDsn) {
            $io->note('Re-running on the owner connection (DATABASE_URL_OWNER): DDL steps need the table-owner role and the seed path bypasses FORCE RLS, exactly like the entrypoint auto-seed.');

            // Confirmation already happened above — the child must not prompt
            // again (and has no TTY to prompt on), so --force is always passed.
            $childCommand = [PHP_BINARY, $this->projectDir.'/bin/console', 'pim:db:reset', '--force'];
            if (true === $loadFixtures) {
                $childCommand[] = '--with-fixtures';
            }
            if (true === $input->getOption('force-prod')) {
                $childCommand[] = '--force-prod';
            }

            $process = new Process(
                $childCommand,
                $this->projectDir,
                ['DATABASE_URL' => $ownerDsn, 'PIM_DB_RESET_ELEVATED' => '1'],
                null,
                // No timeout: migrate + fixtures + full reindex on a large
                // catalog legitimately runs for minutes.
                null,
            );
            $process->run(static function (string $type, string $buffer) use ($output): void {
                $output->write($buffer);
            });

            return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
        }

        // GOLIVE #2178 — drop via raw `DROP DATABASE … WITH (FORCE)` (PG 13+)
        // on a maintenance-DB connection instead of doctrine:database:drop.
        // FrankenPHP (api) and the Messenger consumer (worker) re-open pooled
        // connections within ~1 s, so the documented "terminate sessions, then
        // reset" dance loses the race as often as it wins; FORCE terminates
        // the sessions atomically inside the drop. Runs on the owner
        // connection (`pim`), which holds the required privileges in every
        // compose-provisioned environment.
        try {
            $maintenance = MaintenanceConnectionFactory::fromConnection($this->ownerConnection);
        } catch (Throwable $e) {
            $io->error(sprintf('Cannot open the maintenance connection: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        try {
            // GOLIVE #2178 follow-up — serialise database lifecycle actors.
            // The FORCE drop below kills the worker's Messenger session; the
            // restarted worker's entrypoint re-runs ensure-seeded, which sees
            // a half-reset (empty) database and chains ANOTHER reset — two
            // unserialised resets then kill each other mid-fixtures (observed:
            // "terminating connection due to administrator command" during
            // fixtures:load on a fresh clone). The advisory lock is
            // cluster-wide and held on the maintenance DB, so it survives the
            // drop; concurrent actors block here until this run completes.
            $io->section('→ acquire lifecycle advisory lock');
            // tenant-safe: infrastructure (cluster-wide advisory lock on the maintenance DB; no tenant-scoped data)
            $maintenance->fetchOne(sprintf('SELECT pg_advisory_lock(%d)', MaintenanceConnectionFactory::LIFECYCLE_LOCK_KEY));

            $io->section('→ DROP DATABASE … WITH (FORCE)');
            try {
                $this->dropDatabaseWithForce($maintenance);
            } catch (Throwable $e) {
                $io->error(sprintf('Dropping the database failed: %s', $e->getMessage()));

                return Command::FAILURE;
            }
            // Discard sessions the FORCE drop just killed (see constructor
            // note); every chained step reconnects lazily against the new
            // database.
            $this->defaultConnection->close();
            $this->ownerConnection->close();

            return $this->runResetSteps($io, $output, true === $loadFixtures, $env);
        } finally {
            // Closing the session releases the advisory lock.
            $maintenance->close();
        }
    }

    private function runResetSteps(SymfonyStyle $io, OutputInterface $output, bool $loadFixtures, string $env): int
    {
        $steps = [
            ['doctrine:database:create', ['--if-not-exists' => true]],
            ['doctrine:migrations:migrate', ['--no-interaction' => true, '--allow-no-migration' => true]],
            ['pim:db:schema:validate', []],
        ];

        if ($loadFixtures) {
            $steps[] = ['doctrine:fixtures:load', ['--no-interaction' => true]];
            // Wiping Postgres rotates every tenant UUID (TenantFactory
            // generates fresh ids); without dropping Meili documents the
            // shared `products`/`categories`/… indexes accumulate orphans
            // from previous seeds. Each orphan still carries its old
            // `tenantId` filter value, so admin search hides them — but
            // they break unique-code assumptions, inflate the doc count,
            // and confuse debugging (one `code=DEMO-100` per past seed).
            // The `--purge` flag wipes documents and re-imports cleanly.
            $steps[] = ['pim:search:reindex', ['--kind' => 'all', '--purge' => true]];
        }

        // Wipe stale BulkOperationLock flock files (`sf.bulk-op-{tenant}.lock`)
        // sitting in /tmp from previous tenant generations. The tenant
        // UUID rotates on every fixture reload, so the old lock file
        // never matches the new tenant — but a worker that crashed
        // mid-bulk-run still leaves the lock present for the same
        // tenant ID, blocking subsequent runs for up to the 1h TTL.
        // Resetting the DB is the right point to flush that state.
        $lockDir = sys_get_temp_dir();
        $matches = glob($lockDir.'/sf.bulk-op-*.lock');
        $stale = \is_array($matches) ? $matches : [];
        foreach ($stale as $lockPath) {
            @unlink($lockPath);
        }
        if ([] !== $stale) {
            $io->writeln(sprintf('  cleared %d stale bulk-op lock file(s)', \count($stale)));
        }

        foreach ($steps as [$commandName, $arguments]) {
            $io->section(sprintf('→ %s', $commandName));
            $application = $this->getApplication();
            if (null === $application) {
                $io->error('Console application is not available; refusing to chain commands.');

                return Command::FAILURE;
            }

            $arrayInput = new \Symfony\Component\Console\Input\ArrayInput($arguments);
            // Nested ArrayInput defaults to interactive=true even when the
            // outer command was invoked with --no-interaction. Without this,
            // doctrine:fixtures:load silently aborts on its purge prompt
            // (default answer "no") and pim:db:reset reports success despite
            // an empty fixtures load. Other chained commands (drop/create/
            // migrate) ship a "yes" default so they were never affected.
            $arrayInput->setInteractive(false);

            $exitCode = $application
                ->find($commandName)
                ->run($arrayInput, $output);

            if (Command::SUCCESS !== $exitCode) {
                $io->error(sprintf('Step "%s" failed (exit %d). Aborting.', $commandName, $exitCode));

                return Command::FAILURE;
            }
        }

        $io->success(sprintf(
            'Database reset complete (env=%s%s).',
            $env,
            $loadFixtures ? ', fixtures loaded' : ''
        ));

        return Command::SUCCESS;
    }

    /**
     * DROP DATABASE <target> WITH (FORCE) on the maintenance-DB connection
     * (a database cannot be dropped from a connection to itself). FORCE
     * terminates lingering api/worker sessions atomically — no
     * terminate-then-drop race.
     */
    private function dropDatabaseWithForce(Connection $maintenance): void
    {
        /** @var array{dbname?: string} $params */
        $params = $this->ownerConnection->getParams();
        $dbname = $params['dbname'] ?? '';
        if ('' === $dbname) {
            throw new RuntimeException('Cannot resolve the database name from the owner connection params.');
        }

        $quoted = $maintenance->getDatabasePlatform()->quoteSingleIdentifier($dbname);
        // tenant-safe: infrastructure (dev-only whole-database drop on the maintenance DB; no tenant-scoped rows are queried)
        $maintenance->executeStatement(sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', $quoted));
    }
}
