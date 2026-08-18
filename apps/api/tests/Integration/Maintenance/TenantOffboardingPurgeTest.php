<?php

declare(strict_types=1);

namespace App\Tests\Integration\Maintenance;

use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AUD-019 / AUD-020 (W1-7) — proves tenant offboarding actually erases a
 * tenant end to end (GDPR art. 17).
 *
 * FAILING-FIRST: before the fix, `pim:tenants:purge-deleted` ran a bare
 * `remove($tenant)` → `DELETE FROM tenants`, which hit 24 ON DELETE RESTRICT
 * foreign keys and threw a foreign-key violation for any tenant carrying
 * data — so this test (which seeds a full dependency chain) went RED with an
 * FK error and the dependent rows survived. After the fix the command drives
 * {@see \App\Shared\Infrastructure\Maintenance\TenantPurger}, which deletes
 * dependents in child→parent order + sweeps the object-storage prefix.
 *
 * OPERATOR-DATA SAFETY: this test never touches the real `demo` / `acme`
 * tenants. It creates a THROWAWAY tenant (random uuid) plus a KEEPER tenant
 * (proxy for "another tenant on the platform") and asserts the keeper's rows
 * + storage are untouched after the throwaway is purged. The schema is reset
 * per test (Foundry ResetDatabase), so nothing leaks.
 *
 * RLS note: the test DB schema is built from ORM metadata, so the FORCE RLS
 * policies do NOT exist here and the GUC the purger sets is a no-op — the
 * `WHERE tenant_id = :id` predicate is the active guard. The cross-tenant
 * isolation under real RLS is covered by ForceRlsTenantIsolationTest.
 */
final class TenantOffboardingPurgeTest extends KernelTestCase
{
    use ResetDatabase;

    /**
     * Every tenant-scoped table the purger sweeps. Asserted to reach 0 rows
     * for the throwaway tenant and to stay unchanged for the keeper.
     *
     * @var list<string>
     */
    private const array TENANT_TABLES = [
        'import_undo_log', 'import_logs', 'import_source_logs', 'import_schedule_runs',
        'import_schedules', 'import_sources', 'import_sessions', 'import_profiles',
        'import_staged_files', 'export_sessions', 'export_profiles',
        'object_channel_placements', 'channel_category_node_mappings',
        'channel_category_nodes', 'channel_publication_profiles',
        'object_values', 'object_relations', 'saved_views', 'smart_filter_presets',
        'bulk_edit_jobs', 'objects', 'bulk_sessions', 'object_types',
        'attribute_options', 'attributes', 'attribute_groups',
        'channels', 'assets', 'api_keys', 'api_profiles', 'backups',
        'invitations', 'api_tokens', 'password_reset_tokens',
        'user_tenant_memberships', 'refresh_tokens', 'users',
        'roles', 'sso_providers', 'tenant_locales', 'menu_configurations',
        'tenant_agent_configs',
    ];

    #[Test]
    public function purgesThrowawayTenantEntirelyAndLeavesOtherTenantIntact(): void
    {
        $kernel = self::bootKernel();
        $connection = $this->connection($kernel);
        $assets = $this->storage($kernel, 'assets.storage');
        $imports = $this->storage($kernel, 'imports.storage');
        $exports = $this->storage($kernel, 'exports.storage');

        $throwaway = Uuid::v7();
        $keeper = Uuid::v7();

        // Seed a representative dependency chain that exercises the RESTRICT
        // FKs (objects → object_types, object_values → attributes) for BOTH
        // tenants, plus storage objects under each tenant's prefix.
        $this->seedTenant($connection, $throwaway, 'throwaway');
        $this->seedTenant($connection, $keeper, 'keeper');
        $throwawayObjectValues = $this->seedDataChain($connection, $throwaway);
        $keeperObjectValues = $this->seedDataChain($connection, $keeper);
        $this->seedStorage($assets, $imports, $exports, $throwaway);
        $this->seedStorage($assets, $imports, $exports, $keeper);

        // Sanity: both tenants have data before the purge.
        self::assertSame(1, $this->rowCount($connection, 'object_values', $throwaway));
        self::assertSame(1, $this->rowCount($connection, 'object_values', $keeper));
        self::assertTrue($assets->directoryExists($throwaway->toRfc4122()));

        // Soft-delete ONLY the throwaway, past the retention window.
        $this->softDelete($connection, $throwaway, '-40 days');

        // Run the command (no --dry-run): it must hard-delete the throwaway.
        $tester = $this->commandTester($kernel);
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());

        // ── Throwaway: tenant row + EVERY dependent row gone ───────────
        self::assertSame(
            0,
            $this->countOne($connection, 'SELECT COUNT(*) FROM tenants WHERE id = :id', ['id' => $throwaway->toRfc4122()]),
            'Throwaway tenant row must be hard-deleted.',
        );
        foreach (self::TENANT_TABLES as $table) {
            self::assertSame(
                0,
                $this->rowCount($connection, $table, $throwaway),
                \sprintf('Throwaway tenant must have 0 rows left in `%s`.', $table),
            );
        }
        // Targeted check on the row whose FK chain (object_values →
        // attributes RESTRICT) was the original blocker.
        self::assertSame(
            0,
            $this->countOne($connection, 'SELECT COUNT(*) FROM object_values WHERE id = :id', ['id' => $throwawayObjectValues]),
        );

        // ── Throwaway storage prefix swept across all three buckets ────
        self::assertFalse($assets->directoryExists($throwaway->toRfc4122()), 'Throwaway assets prefix must be gone.');
        self::assertFalse($imports->directoryExists($throwaway->toRfc4122()), 'Throwaway imports prefix must be gone.');
        self::assertFalse($exports->directoryExists($throwaway->toRfc4122()), 'Throwaway exports prefix must be gone.');

        // ── KEEPER tenant fully intact (proxy for demo/acme) ───────────
        self::assertSame(
            1,
            $this->countOne($connection, 'SELECT COUNT(*) FROM tenants WHERE id = :id', ['id' => $keeper->toRfc4122()]),
            'Keeper tenant must be untouched.',
        );
        self::assertSame(1, $this->rowCount($connection, 'object_values', $keeper), 'Keeper object_values intact.');
        self::assertSame(1, $this->rowCount($connection, 'objects', $keeper), 'Keeper objects intact.');
        self::assertSame(1, $this->rowCount($connection, 'assets', $keeper), 'Keeper assets intact.');
        self::assertSame(1, $this->rowCount($connection, 'users', $keeper), 'Keeper users intact.');
        self::assertSame(
            1,
            $this->countOne($connection, 'SELECT COUNT(*) FROM object_values WHERE id = :id', ['id' => $keeperObjectValues]),
        );
        self::assertTrue($assets->directoryExists($keeper->toRfc4122()), 'Keeper assets prefix intact.');
        self::assertTrue($exports->directoryExists($keeper->toRfc4122()), 'Keeper exports prefix intact.');
    }

    /**
     * TNT-P4-08 (#2909) — po wygaśnięciu okna odzyskania giną nie tylko wiersze
     * w bazie, ale i stack instancji. To JEDYNE miejsce, które zleca `purge`;
     * panel zleca `delete`, czyli operację odwracalną.
     */
    #[Test]
    public function ordersTheInstanceStackDestroyedOnceTheWindowHasExpired(): void
    {
        $spool = sys_get_temp_dir().'/pim-spool-purge-'.bin2hex(random_bytes(6));
        mkdir($spool);
        $_ENV['PROVISIONER_SPOOL'] = $spool;
        $_SERVER['PROVISIONER_SPOOL'] = $spool;

        try {
            $kernel = self::bootKernel();
            $connection = $this->connection($kernel);

            $throwaway = Uuid::v7();
            $this->seedTenant($connection, $throwaway, 'purge-stack');
            $this->softDelete($connection, $throwaway, '-40 days');

            $tester = $this->commandTester($kernel);
            $tester->execute([]);

            $jobs = glob($spool.'/*.job.json');
            self::assertIsArray($jobs);
            self::assertCount(1, $jobs, $tester->getDisplay());

            $job = json_decode((string) file_get_contents($jobs[0]), true);
            self::assertIsArray($job);
            self::assertSame('purge', $job['action']);
            // `seedTenant()` dokleja losowy przyrostek, żeby kody się nie
            // zderzały między przebiegami — sprawdzamy prefiks, nie równość.
            self::assertIsString($job['code']);
            self::assertStringStartsWith('purge-stack', $job['code']);
        } finally {
            $leftovers = glob($spool.'/*');
            foreach (false === $leftovers ? [] : $leftovers as $file) {
                unlink($file);
            }
            rmdir($spool);
            unset($_ENV['PROVISIONER_SPOOL'], $_SERVER['PROVISIONER_SPOOL']);
        }
    }

    #[Test]
    public function dryRunDeletesNothing(): void
    {
        $kernel = self::bootKernel();
        $connection = $this->connection($kernel);

        $throwaway = Uuid::v7();
        $this->seedTenant($connection, $throwaway, 'dry-run');
        $this->seedDataChain($connection, $throwaway);
        $this->softDelete($connection, $throwaway, '-40 days');

        $tester = $this->commandTester($kernel);
        $exit = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertSame(
            1,
            $this->countOne($connection, 'SELECT COUNT(*) FROM tenants WHERE id = :id', ['id' => $throwaway->toRfc4122()]),
            'Dry-run must not delete the tenant row.',
        );
        self::assertSame(1, $this->rowCount($connection, 'object_values', $throwaway), 'Dry-run must keep dependents.');
    }

    #[Test]
    public function leavesTenantsStillInsideRetentionWindow(): void
    {
        $kernel = self::bootKernel();
        $connection = $this->connection($kernel);

        $recent = Uuid::v7();
        $this->seedTenant($connection, $recent, 'recent');
        $this->seedDataChain($connection, $recent);
        // Soft-deleted only 5 days ago — inside the default 30-day window.
        $this->softDelete($connection, $recent, '-5 days');

        $tester = $this->commandTester($kernel);
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertSame(
            1,
            $this->countOne($connection, 'SELECT COUNT(*) FROM tenants WHERE id = :id', ['id' => $recent->toRfc4122()]),
            'A tenant soft-deleted inside the retention window must survive.',
        );
    }

    private function seedTenant(Connection $connection, Uuid $id, string $codePrefix): void
    {
        $suffix = bin2hex(random_bytes(4));
        $connection->executeStatement(
            'INSERT INTO tenants (id, code, name, created_at) VALUES (:id, :code, :name, NOW())',
            ['id' => $id->toRfc4122(), 'code' => $codePrefix.'-'.$suffix, 'name' => $codePrefix],
        );
    }

    /**
     * Seeds object_type → object → object_value(+attribute) + attribute_group
     * + asset + user + export_profile → export_session for the given tenant.
     * Returns the object_value id (the row guarded by the RESTRICT FK to
     * attributes that originally blocked the purge).
     */
    private function seedDataChain(Connection $connection, Uuid $tenant): string
    {
        $t = $tenant->toRfc4122();
        $suffix = bin2hex(random_bytes(4));

        $objectTypeId = Uuid::v7()->toRfc4122();
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO object_types
                    (id, tenant_id, code, kind, is_built_in, code_immutable, deletable, label,
                     completeness_rules, hierarchical, has_variants, abstract, expose_to_main_menu,
                     is_categorizable, has_multimedia, allowed_parent_type_ids, schema_version,
                     created_at, updated_at)
                VALUES
                    (:id, :t, :code, 'product', false, false, true, '{}'::jsonb,
                     '{}'::jsonb, false, false, false, true,
                     false, false, '[]'::jsonb, 1,
                     NOW(), NOW())
                SQL,
            ['id' => $objectTypeId, 't' => $t, 'code' => 'ot-'.$suffix],
        );

        $attributeGroupId = Uuid::v7()->toRfc4122();
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO attribute_groups
                    (id, tenant_id, code, label, is_system_group, auto_attached, is_required_section,
                     is_shared, has_conditional_visibility, position, created_at)
                VALUES (:id, :t, :code, '{}'::jsonb, false, false, false, false, false, 0, NOW())
                SQL,
            ['id' => $attributeGroupId, 't' => $t, 'code' => 'ag-'.$suffix],
        );

        $attributeId = Uuid::v7()->toRfc4122();
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO attributes
                    (id, tenant_id, code, label, type, is_localizable, is_scopable, is_required,
                     is_filterable, is_system, validation_rules, relation_target_object_type_ids,
                     relation_advanced, relation_preview_fields, position, created_at, group_id)
                VALUES
                    (:id, :t, :code, '{}'::jsonb, 'text', false, false, false,
                     false, false, '{}'::jsonb, '[]'::jsonb,
                     false, '[]'::jsonb, 0, NOW(), :gid)
                SQL,
            ['id' => $attributeId, 't' => $t, 'code' => 'attr-'.$suffix, 'gid' => $attributeGroupId],
        );

        $objectId = Uuid::v7()->toRfc4122();
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO objects
                    (id, tenant_id, object_type_id, kind, code, enabled, status, completeness,
                     completeness_pct, sync_status_aggregate, attributes_indexed, schema_drift,
                     created_at, updated_at, version)
                VALUES
                    (:id, :t, :ot, 'product', :code, true, 'draft', '{}'::jsonb,
                     0, 'never', '{}'::jsonb, false,
                     NOW(), NOW(), 1)
                SQL,
            ['id' => $objectId, 't' => $t, 'ot' => $objectTypeId, 'code' => 'obj-'.$suffix],
        );

        $objectValueId = Uuid::v7()->toRfc4122();
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO object_values
                    (id, tenant_id, object_id, attribute_id, value, provenance, provenance_meta)
                VALUES (:id, :t, :obj, :attr, :value, 'manual', '{}'::jsonb)
                SQL,
            [
                'id' => $objectValueId,
                't' => $t,
                'obj' => $objectId,
                'attr' => $attributeId,
                'value' => '{"value": "hello"}',
            ],
        );

        $assetId = Uuid::v7()->toRfc4122();
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO assets
                    (id, tenant_id, code, original_filename, mime_type, size, metadata, storage_path,
                     created_at, tags, thumbnails_status)
                VALUES
                    (:id, :t, :code, 'photo.jpg', 'image/jpeg', 1234, '{}'::jsonb, :path,
                     NOW(), '[]'::jsonb, 'pending')
                SQL,
            [
                'id' => $assetId,
                't' => $t,
                'code' => 'asset-'.$suffix,
                'path' => $t.'/'.$assetId.'/original.jpg',
            ],
        );

        $userId = Uuid::v7()->toRfc4122();
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO users (id, email, password, roles, status, totp_backup_codes,
                                   created_at, password_change_required, tenant_id)
                VALUES (:id, :email, 'x', '[]'::json, 'active', '[]'::jsonb,
                        NOW(), false, :t)
                SQL,
            ['id' => $userId, 'email' => 'user-'.$suffix.'@example.test', 't' => $t],
        );

        $exportProfileId = Uuid::v7()->toRfc4122();
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO export_profiles
                    (id, tenant_id, user_id, name, entity_type, config, run_count, created_at, updated_at)
                VALUES (:id, :t, :uid, :name, 'product', '{}'::jsonb, 0, NOW(), NOW())
                SQL,
            ['id' => $exportProfileId, 't' => $t, 'uid' => $userId, 'name' => 'exp-'.$suffix],
        );

        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO export_sessions
                    (id, tenant_id, user_id, source, format, target_scope, entity_type,
                     selected_columns, include_variants, target_count, success_count,
                     status, started_at, profile_id)
                VALUES
                    (:id, :t, :uid, 'manual', 'xlsx', 'all', 'product',
                     '[]'::jsonb, false, 0, 0,
                     'pending', NOW(), :pid)
                SQL,
            ['id' => Uuid::v7()->toRfc4122(), 't' => $t, 'uid' => $userId, 'pid' => $exportProfileId],
        );

        return $objectValueId;
    }

    private function seedStorage(
        FilesystemOperator $assets,
        FilesystemOperator $imports,
        FilesystemOperator $exports,
        Uuid $tenant,
    ): void {
        $prefix = $tenant->toRfc4122();
        $assets->write($prefix.'/asset/original.jpg', 'binary-bytes');
        $imports->write($prefix.'/upload/source.csv', 'a,b,c');
        $exports->write($prefix.'/session.xlsx', 'xlsx-bytes');
    }

    private function softDelete(Connection $connection, Uuid $tenant, string $when): void
    {
        $connection->executeStatement(
            "UPDATE tenants SET status = 'deleted', deleted_at = NOW() + (:when)::interval WHERE id = :id",
            ['when' => $when, 'id' => $tenant->toRfc4122()],
        );
    }

    private function rowCount(Connection $connection, string $table, Uuid $tenant): int
    {
        // $table comes only from the class constant TENANT_TABLES (no
        // external input) — safe to inline.
        return $this->countOne(
            $connection,
            \sprintf('SELECT COUNT(*) FROM %s WHERE tenant_id = :t', $table),
            ['t' => $tenant->toRfc4122()],
        );
    }

    /**
     * COUNT(*) helper — DBAL fetchOne() returns mixed; narrow to int safely.
     *
     * @param array<string, scalar> $params
     */
    private function countOne(Connection $connection, string $sql, array $params): int
    {
        $value = $connection->fetchOne($sql, $params);

        return \is_numeric($value) ? (int) $value : 0;
    }

    private function commandTester(object $kernel): CommandTester
    {
        \assert($kernel instanceof \Symfony\Component\HttpKernel\KernelInterface);
        $application = new Application($kernel);

        return new CommandTester($application->find('pim:tenants:purge-deleted'));
    }

    private function connection(object $kernel): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');

        return $connection;
    }

    private function storage(object $kernel, string $serviceId): FilesystemOperator
    {
        $storage = self::getContainer()->get($serviceId);
        \assert($storage instanceof FilesystemOperator);

        return $storage;
    }
}
