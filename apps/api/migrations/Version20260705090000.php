<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DASH-09 (#2265, ADR-0026) — `dashboard_alert_acks`: the "oznacz jako
 * przeczytane" state for the action-center feed. Alerts themselves are
 * aggregated on the fly from the four status tables + the snapshot-based
 * completeness-drop detector (operator decision 2026-07-04: NO
 * materialized Alert entity); this table only remembers which
 * deterministic fingerprint a tenant acknowledged. The time window
 * replaces TTL, so no cleanup job is required.
 *
 * TenantScoped with the W1-1 FORCE RLS pattern + explicit runtime-role
 * GRANT (#2177 — default privileges are lost on pg_restore and the ack
 * INSERT runs as `pim_app`).
 */
final class Version20260705090000 extends AbstractMigration
{
    private const string TABLE = 'dashboard_alert_acks';

    public function getDescription(): string
    {
        return 'DASH-09: add dashboard_alert_acks (fingerprint ack state) + FORCE RLS + pim_app grant.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE dashboard_alert_acks (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                fingerprint VARCHAR(160) NOT NULL,
                acked_by UUID DEFAULT NULL,
                acked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql(
            'CREATE UNIQUE INDEX dashboard_alert_acks_tenant_fingerprint_uniq '
            .'ON dashboard_alert_acks (tenant_id, fingerprint)'
        );
        $this->addSql('CREATE INDEX dashboard_alert_acks_tenant_idx ON dashboard_alert_acks (tenant_id)');
        $this->addSql(
            'ALTER TABLE dashboard_alert_acks ADD CONSTRAINT FK_dashboard_alert_acks_tenant '
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
        $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON dashboard_alert_acks TO pim_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(\sprintf('DROP POLICY IF EXISTS super_admin_bypass_%s ON %s', self::TABLE, self::TABLE));
        $this->addSql(\sprintf('DROP POLICY IF EXISTS tenant_isolation_%s ON %s', self::TABLE, self::TABLE));
        $this->addSql(\sprintf('ALTER TABLE %s NO FORCE ROW LEVEL SECURITY', self::TABLE));
        $this->addSql(\sprintf('ALTER TABLE %s DISABLE ROW LEVEL SECURITY', self::TABLE));
        $this->addSql('DROP TABLE dashboard_alert_acks');
    }
}
