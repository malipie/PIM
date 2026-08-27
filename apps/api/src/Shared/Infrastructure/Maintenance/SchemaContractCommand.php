<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Maintenance;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use const SORT_STRING;

/**
 * Read-only schema contract for deployment and takeover diagnostics.
 *
 * ORM tables are discovered from current metadata. Everything outside that
 * set must be explicitly assigned to the auditor or migrations-only allowlist
 * below. The intentionally noisy Doctrine schema diff is pinned as a sorted
 * fingerprint: a new difference cannot silently join the historical debt.
 */
#[AsCommand(
    name: 'pim:db:schema:validate',
    description: 'Validate migrations, auditor DDL, table ownership and the allowlisted ORM schema drift.'
)]
final class SchemaContractCommand extends Command
{
    /** @var list<string> */
    private const array AUDITOR_TABLES = [
        'api_keys_audit',
        'api_profiles_audit',
        'assets_audit',
        'attribute_groups_audit',
        'attribute_options_audit',
        'attributes_audit',
        'backups_audit',
        'channels_audit',
        'import_profiles_audit',
        'import_sessions_audit',
        'object_types_audit',
        'permissions_audit',
        'roles_audit',
        'tenants_audit',
        'users_audit',
    ];

    /**
     * Tables deliberately not represented by active ORM metadata. Historical
     * audit tables stay readable, while infra/junction tables remain owned by
     * their migrations until a separate, explicit retirement migration.
     *
     * @var list<string>
     */
    private const array MIGRATIONS_ONLY_TABLES = [
        'channel_object_type_mappings_audit',
        'doctrine_migration_versions',
        'messenger_messages',
        'object_values_audit',
        'objects_audit',
        'processed_messages',
        'product_assets',
        'role_attribute_group_permissions',
    ];

    /** Re-baseline only after reviewing every statement printed on failure. */
    private const int ORM_DRIFT_STATEMENT_COUNT = 192;
    private const string ORM_DRIFT_SHA256 = 'e14fe0dad6c3dc6bb77465cb0b95c55f7be5481ab750aff800d53a247d771226';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.owner_connection')]
        private readonly Connection $ownerConnection,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $errors = [];

        [$ormDriftOk, $ormDriftOutput] = $this->runConsole('doctrine:schema:update', '--dump-sql');
        $ormDrift = $this->normaliseSqlStatements($ormDriftOutput);
        // A nested schema command does not inherit the migrations command's
        // schema-assets filter and proposes dropping its version table. That
        // exact table is owned and presence-checked separately below.
        $ormDrift = array_values(array_diff($ormDrift, ['DROP TABLE doctrine_migration_versions;']));
        $ormDriftHash = hash('sha256', implode("\n", $ormDrift)."\n");
        if (
            !$ormDriftOk
            || self::ORM_DRIFT_STATEMENT_COUNT !== \count($ormDrift)
            || self::ORM_DRIFT_SHA256 !== $ormDriftHash
        ) {
            $errors[] = sprintf(
                'ORM drift differs from the reviewed allowlist: %d statement(s), sha256=%s. Review `doctrine:schema:update --dump-sql`; never run it with --force.',
                \count($ormDrift),
                $ormDriftHash,
            );
        }

        [$migrationsOk, $migrationsOutput] = $this->runConsole('doctrine:migrations:up-to-date');
        if (!$migrationsOk) {
            $errors[] = 'Migrations are not up to date: '.trim($migrationsOutput);
        }

        [$auditorOk, $auditorOutput] = $this->runConsole('audit:schema:update', '--dump-sql');
        $auditorSql = $this->normaliseSqlStatements($auditorOutput);
        if (!$auditorOk || [] !== $auditorSql) {
            $errors[] = sprintf(
                'DH Auditor schema drifted (%d statement(s)): %s',
                \count($auditorSql),
                implode(' | ', array_slice($auditorSql, 0, 10)),
            );
        }

        [$owners, $ownershipErrors] = $this->expectedTableOwners();
        array_push($errors, ...$ownershipErrors);

        $actualTables = $this->ownerConnection->fetchFirstColumn(<<<'SQL'
            SELECT tablename
            FROM pg_catalog.pg_tables
            WHERE schemaname = 'public'
            ORDER BY tablename
            SQL);
        $actualTables = array_values(array_filter($actualTables, 'is_string'));

        $missing = array_values(array_diff(array_keys($owners), $actualTables));
        $unowned = array_values(array_diff($actualTables, array_keys($owners)));
        if ([] !== $missing) {
            $errors[] = 'Contract tables missing from public schema: '.implode(', ', $missing);
        }
        if ([] !== $unowned) {
            $errors[] = 'Public tables without an owner: '.implode(', ', $unowned);
        }

        $ownerCounts = array_count_values($owners);
        $io->definitionList(
            ['migrations' => $migrationsOk ? 'up to date' : 'DRIFT'],
            ['auditor DDL' => [] === $auditorSql && $auditorOk ? 'clean' : 'DRIFT'],
            ['public tables' => \count($actualTables)],
            ['ORM-owned' => $ownerCounts['ORM'] ?? 0],
            ['auditor-owned' => $ownerCounts['auditor'] ?? 0],
            ['migrations-only' => $ownerCounts['migrations-only'] ?? 0],
            ['ORM drift allowlist' => sprintf('%d statement(s), %s', \count($ormDrift), $ormDriftHash)],
        );

        if ([] !== $errors) {
            $io->error($errors);

            return Command::FAILURE;
        }

        $io->success('Database schema matches the migration, auditor, ownership and reviewed-drift contracts.');

        return Command::SUCCESS;
    }

    /**
     * @return array{array<string, 'ORM'|'auditor'|'migrations-only'>, list<string>}
     */
    private function expectedTableOwners(): array
    {
        $owners = [];
        $errors = [];

        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass) {
                continue;
            }

            $this->assignOwner($owners, $errors, $metadata->getTableName(), 'ORM');

            foreach ($metadata->getAssociationMappings() as $mapping) {
                if ($mapping instanceof ManyToManyOwningSideMapping) {
                    $this->assignOwner($owners, $errors, $mapping->joinTable->name, 'ORM');
                }
            }
        }

        foreach (self::AUDITOR_TABLES as $table) {
            $this->assignOwner($owners, $errors, $table, 'auditor');
        }
        foreach (self::MIGRATIONS_ONLY_TABLES as $table) {
            $this->assignOwner($owners, $errors, $table, 'migrations-only');
        }

        ksort($owners);

        return [$owners, $errors];
    }

    /**
     * @param array<string, 'ORM'|'auditor'|'migrations-only'> $owners
     * @param list<string>                                     $errors
     * @param 'ORM'|'auditor'|'migrations-only'                $owner
     */
    private function assignOwner(array &$owners, array &$errors, string $table, string $owner): void
    {
        if (isset($owners[$table]) && $owners[$table] !== $owner) {
            $errors[] = sprintf('Table %s has two owners: %s and %s.', $table, $owners[$table], $owner);

            return;
        }

        $owners[$table] = $owner;
    }

    /** @return array{bool, string} */
    private function runConsole(string $commandName, string ...$options): array
    {
        $application = $this->getApplication();
        if (null === $application) {
            return [false, 'Console application is not available.'];
        }

        $parameters = [];
        foreach ($options as $option) {
            $parameters[$option] = true;
        }

        $input = new ArrayInput($parameters);
        $input->setInteractive(false);
        $output = new BufferedOutput();
        $exitCode = $application->find($commandName)->run($input, $output);

        return [Command::SUCCESS === $exitCode, $output->fetch()];
    }

    /** @return list<string> */
    private function normaliseSqlStatements(string $output): array
    {
        $statements = [];
        $lines = preg_split('/\R/', $output);
        if (false === $lines) {
            return [];
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if (1 !== preg_match('/^(?:ALTER|COMMENT|CREATE|DROP)\b/i', $line)) {
                continue;
            }

            $normalised = preg_replace('/\s+/', ' ', $line);
            if (\is_string($normalised)) {
                $statements[] = $normalised;
            }
        }

        sort($statements, SORT_STRING);

        return $statements;
    }
}
