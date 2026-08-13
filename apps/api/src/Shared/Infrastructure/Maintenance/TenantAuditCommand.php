<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Maintenance;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Audit every public-schema table for `tenant_id` coverage.
 *
 * What it checks:
 *   - Domain tables (everything outside the {@see INFRA_TABLES} allowlist)
 *     must have a `tenant_id` column.
 *   - That column should be `NOT NULL` unless the table is on the
 *     {@see NULLABLE_TENANT_TABLES} list (currently only `roles` — global
 *     built-in roles persist with `tenant_id IS NULL`).
 *   - That column should carry an index — perf concern, not correctness.
 *
 * Exit codes:
 *   - 0: clean (no FAILs).
 *   - 1: at least one FAIL detected (missing tenant_id, or unexpected
 *     nullable, or unindexed). The CLI prints the table-by-table report so
 *     the operator knows exactly which row to fix.
 *
 * Idempotent and read-only — safe to run on production.
 *
 * See `docs/multi-tenancy.md` for context. Future entities (`Object`,
 * `Channel`, `Asset`) added in epic 0.3 should land green on first audit;
 * if a new domain table forgets `tenant_id`, this command catches it.
 */
#[AsCommand(
    name: 'pim:tenant:audit',
    description: 'Audit every public-schema domain table for tenant_id coverage.'
)]
final class TenantAuditCommand extends Command
{
    private const string SEVERITY_OK = 'OK';
    private const string SEVERITY_WARN = 'WARN';
    private const string SEVERITY_FAIL = 'FAIL';

    /**
     * Tables that are infrastructure or unscoped by design — they should NOT
     * be flagged when they lack `tenant_id`. Anything outside this list is
     * treated as domain and must carry the column.
     *
     * Notes:
     *   - `tenants`: the tenant entity itself; not scoped.
     *   - `permissions`: catalog of (resource, action) pairs, intentionally
     *     global (#27 / RbacMatrix).
     *   - `role_permissions`: M2M junction; tenant scope follows the
     *     parent rows.
     *   - `messenger_messages`: Symfony Messenger queue, infra.
     *   - `doctrine_migration_versions`: Doctrine bookkeeping.
     *
     * @var list<string>
     */
    private const array INFRA_TABLES = [
        'tenants',
        'permissions',
        'role_permissions',
        'messenger_messages',
        'doctrine_migration_versions',
        // ObjectType ↔ Attribute junction (#32). Tenant scope inherited via
        // the parent ObjectType row; no own tenant_id column.
        'object_type_attributes',
        // Channel context shared infrastructure (#36): locales are global
        // rows referenced by every tenant.
        'locales',
        // Asset variants (#37) inherit tenant scope from the parent Asset.
        'asset_variants',
        // ADR-012 / UI-08.1 — AttributeGroup junction tables. Tenant scope
        // inherited via the parent AttributeGroup / ObjectType / category row.
        'attribute_group_attributes',
        'object_type_attribute_groups',
        'category_attribute_groups',
        // PCAT-01 (#474) / UI-10 — product↔category assignments. Tenant
        // scope inherited via FK to objects(tenant_id).
        'object_categories',
        // UI-08.6 (#261) — pre-migration JSONB backup snapshots. Tenant
        // scope inherited via the parent attribute.
        'attribute_migration_backups',
        // IMP2-2.5 (#1481) — import_logs LEFT this list: it now carries its
        // own tenant_id + RLS (FORCE-RLS readiness), so the audit verifies the
        // column directly instead of exempting it.
        // VIEW-12 (#543) — bulk_logs inherits tenant scope through the
        // parent bulk_session (FK CASCADE on delete); keeps the
        // append-only log free of redundant tenant columns.
        'bulk_logs',
        // VIEW-27 (#558) — per-user attribute favorites junction. Tenant
        // scope inherited via users.tenant_id (FK CASCADE on user delete).
        'user_filter_favorites',
        // EXP-01 (#580) — per-job export trace. Tenant scope inherited
        // via the parent ExportSession (FK CASCADE on delete); same
        // pattern as bulk_logs. (Pairs with IMP2-2.6+ if export_logs ever
        // needs direct RLS like import_logs got in IMP2-2.5.)
        'export_logs',
        // RBAC-P1-008 (#647) — platform-level operators. No tenant_id by
        // design; SuperAdmin sits outside the tenant boundary and is
        // forensically traceable via `cross_tenant_access=true` in
        // audit_logs (Phase 3 #677, Phase 5 #712).
        'super_admins',
        // RBAC-P1-008 (#647) — per-user role assignment with scope
        // (locale/channel/attribute_groups). Junction; tenant scope
        // inherited via user_id FK to users(tenant_id). Sole record of who
        // holds what since ADR-0034 (#2832 expand, #2836 contract).
        'user_role_assignments',
        // RBAC-P5-007 (#697) — per-attribute 3-state permission override
        // on a role. Junction; tenant scope inherited via role_id FK to
        // roles(tenant_id), and the attribute_id half is validated against
        // the caller's tenant at write time by the controller.
        'role_attribute_permissions',
    ];

    /**
     * Domain tables where `tenant_id` is legitimately nullable (global rows
     * carry NULL). Audit downgrades the nullability check to OK for these.
     *
     * @var list<string>
     */
    private const array NULLABLE_TENANT_TABLES = [
        'roles',
        // VIEW-09 (#535) — system-shipped Smart Filter Presets are
        // shared across every tenant via `tenant_id IS NULL` (built-in
        // rows). User-defined presets fill the column. See
        // `App\Shared\Application\SystemShipped` marker contract.
        'smart_filter_presets',
        // RBAC-P3-013 (#676) — RBAC-aware audit log. `tenant_id` is
        // nullable by design because Super Admin cross-tenant operations
        // carry no tenant context (e.g. /api/admin/tenants listing).
        // `cross_tenant_access=true` + `super_admin_id` flag those rows
        // forensically.
        'audit_logs',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tables = $this->fetchPublicTables();
        $rows = [];
        $failures = 0;

        foreach ($tables as $table) {
            if (\in_array($table, self::INFRA_TABLES, true)) {
                $rows[] = [$table, 'infra (skipped)', '—', '—', self::SEVERITY_OK];
                continue;
            }

            // DH Auditor (`#99`) writes per-entity tables matching
            // `<entity>_audit`. The bundle stores `blame_id` (the
            // mutating user) rather than `tenant_id`; tenant scope is
            // inherited from the audited row through `object_id`.
            if (str_ends_with($table, '_audit')) {
                $rows[] = [$table, 'audit log (skipped)', '—', '—', self::SEVERITY_OK];
                continue;
            }

            $column = $this->fetchTenantIdColumn($table);
            if (null === $column) {
                $rows[] = [$table, 'MISSING', '—', '—', self::SEVERITY_FAIL];
                ++$failures;
                continue;
            }

            $nullable = 'YES' === $column['is_nullable'];
            $nullableSeverity = self::SEVERITY_OK;
            if ($nullable && !\in_array($table, self::NULLABLE_TENANT_TABLES, true)) {
                $nullableSeverity = self::SEVERITY_FAIL;
                ++$failures;
            }

            $hasIndex = $this->columnHasIndex($table, 'tenant_id');
            $indexSeverity = $hasIndex ? self::SEVERITY_OK : self::SEVERITY_WARN;

            // Worst severity from the two candidate checks. PHPStan
            // narrows $nullableSeverity to 'OK'|'FAIL' (line ~165) and
            // $indexSeverity to 'OK'|'WARN' (line ~181), so we only test
            // the values each variable can actually hold. FAIL beats
            // WARN beats OK.
            if (self::SEVERITY_FAIL === $nullableSeverity) {
                $worst = self::SEVERITY_FAIL;
            } elseif (self::SEVERITY_WARN === $indexSeverity) {
                $worst = self::SEVERITY_WARN;
            } else {
                $worst = self::SEVERITY_OK;
            }

            $rows[] = [
                $table,
                'present',
                $nullable ? 'NULL OK' : 'NOT NULL',
                $hasIndex ? 'indexed' : 'no index',
                $worst,
            ];
        }

        $io->table(['table', 'tenant_id', 'nullability', 'index', 'severity'], $rows);

        if ($failures > 0) {
            $io->error(\sprintf('Audit found %d failure(s).', $failures));

            return Command::FAILURE;
        }

        $io->success('All domain tables carry tenant_id.');

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function fetchPublicTables(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name",
        );
        $names = [];
        foreach ($rows as $row) {
            $name = $row['table_name'] ?? null;
            if (\is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return array{is_nullable: string}|null
     */
    private function fetchTenantIdColumn(string $table): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :table AND column_name = 'tenant_id'",
            ['table' => $table],
        );
        if (false === $row) {
            return null;
        }

        $nullable = $row['is_nullable'] ?? null;
        if (!\is_string($nullable)) {
            return null;
        }

        return ['is_nullable' => $nullable];
    }

    private function columnHasIndex(string $table, string $column): bool
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                    SELECT 1
                    FROM pg_index i
                    JOIN pg_class c ON c.oid = i.indrelid
                    JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = ANY(i.indkey)
                    WHERE c.relname = :table AND a.attname = :column
                    LIMIT 1
                SQL,
            ['table' => $table, 'column' => $column],
        );

        return false !== $row;
    }
}
