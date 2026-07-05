<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DASH-05 (#2257, ADR-0026) — `dashboard_snapshots`: one row per tenant per
 * day with the dashboard KPI aggregates (products total, publish-ready
 * count, avg completeness, per-channel JSONB). Written daily by
 * `pim:dashboard:snapshot` on the maintenance schedule; read by the
 * summary endpoint to compute honest deltas and by the completeness-drop
 * alert detector (DASH-09).
 *
 * TenantScoped with the W1-1 FORCE RLS pattern (Version20260628110000):
 * fail-closed tenant-isolation policy + super-admin break-glass bypass.
 * Explicit runtime-role GRANT per the #2177 pattern — default privileges
 * are lost on pg_restore (see Version20260704050000 docblock), and the
 * snapshot job INSERTs as `pim_app` from the worker.
 */
final class Version20260705060000 extends AbstractMigration
{
    private const string TABLE = 'dashboard_snapshots';

    public function getDescription(): string
    {
        return 'DASH-05: add dashboard_snapshots (daily KPI aggregates per tenant) + FORCE RLS + pim_app grant.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE dashboard_snapshots (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                snapshot_date DATE NOT NULL,
                products_total INT NOT NULL,
                publish_ready_count INT NOT NULL,
                avg_completeness_pct SMALLINT NOT NULL,
                per_channel JSONB NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql(
            'CREATE UNIQUE INDEX dashboard_snapshots_tenant_date_uniq '
            .'ON dashboard_snapshots (tenant_id, snapshot_date)'
        );
        $this->addSql('CREATE INDEX dashboard_snapshots_tenant_idx ON dashboard_snapshots (tenant_id)');
        $this->addSql(
            'ALTER TABLE dashboard_snapshots ADD CONSTRAINT FK_dashboard_snapshots_tenant '
            .'FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE'
        );

        // ── Row-Level Security (W1-1 FORCE pattern) ───────────────────────
        $predicate = "tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid";

        $this->addSql(\sprintf('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', self::TABLE));
        $this->addSql(\sprintf('ALTER TABLE %s FORCE ROW LEVEL SECURITY', self::TABLE));
        $this->addSql(\sprintf(
            'CREATE POLICY tenant_isolation_%s ON %s USING (%s) WITH CHECK (%s)',
            self::TABLE,
            self::TABLE,
            $predicate,
            $predicate,
        ));
        $this->addSql(\sprintf(
            'CREATE POLICY super_admin_bypass_%s ON %s '
            ."USING (current_setting('app.is_super_admin', true) = 'true') "
            ."WITH CHECK (current_setting('app.is_super_admin', true) = 'true')",
            self::TABLE,
            self::TABLE,
        ));

        // Runtime-role grant (#2177): survives pg_restore privilege loss.
        $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON dashboard_snapshots TO pim_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(\sprintf('DROP POLICY IF EXISTS super_admin_bypass_%s ON %s', self::TABLE, self::TABLE));
        $this->addSql(\sprintf('DROP POLICY IF EXISTS tenant_isolation_%s ON %s', self::TABLE, self::TABLE));
        $this->addSql(\sprintf('ALTER TABLE %s NO FORCE ROW LEVEL SECURITY', self::TABLE));
        $this->addSql(\sprintf('ALTER TABLE %s DISABLE ROW LEVEL SECURITY', self::TABLE));
        $this->addSql('DROP TABLE dashboard_snapshots');
    }
}
