<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AGENT-P0-03 (#1946) — `pending_changes`: core approval-gate hook
 * (ADR-0024 c). Producers (the agent BC first) materialize proposed
 * changes here; nothing reaches the catalog before a human accept.
 *
 * Tenant-scoped with Postgres RLS (defence in depth on top of the
 * Doctrine TenantFilter) using the `app.current_tenant` GUC, plus the
 * Super Admin bypass flag — same pattern as import_staged_files.
 */
final class Version20260702090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AGENT-P0-03: pending_changes table + tenant RLS policies';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE pending_changes (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                batch_id UUID NOT NULL,
                provenance VARCHAR(32) NOT NULL,
                change_type VARCHAR(16) NOT NULL,
                status VARCHAR(16) DEFAULT 'pending' NOT NULL,
                target_object_id UUID DEFAULT NULL,
                attribute_code VARCHAR(255) DEFAULT NULL,
                scope_locale VARCHAR(16) DEFAULT NULL,
                scope_channel VARCHAR(64) DEFAULT NULL,
                before_state JSONB DEFAULT NULL,
                after_state JSONB DEFAULT NULL,
                meta JSONB DEFAULT NULL,
                cost_usd NUMERIC(12, 6) DEFAULT NULL,
                tokens INT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX pending_changes_tenant_status_idx ON pending_changes (tenant_id, status)');
        $this->addSql('CREATE INDEX pending_changes_batch_idx ON pending_changes (batch_id)');
        $this->addSql(
            'ALTER TABLE pending_changes ADD CONSTRAINT fk_pending_changes_tenant '
            .'FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE'
        );

        $predicate = "tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid";
        $this->addSql('ALTER TABLE pending_changes ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE pending_changes FORCE ROW LEVEL SECURITY');
        $this->addSql(\sprintf(
            'CREATE POLICY tenant_isolation_pending_changes ON pending_changes USING (%s) WITH CHECK (%s)',
            $predicate,
            $predicate,
        ));
        $this->addSql(
            'CREATE POLICY super_admin_bypass_pending_changes ON pending_changes '
            ."USING (current_setting('app.is_super_admin', true) = 'true') "
            ."WITH CHECK (current_setting('app.is_super_admin', true) = 'true')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS super_admin_bypass_pending_changes ON pending_changes');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation_pending_changes ON pending_changes');
        $this->addSql('ALTER TABLE pending_changes NO FORCE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE pending_changes DISABLE ROW LEVEL SECURITY');
        $this->addSql('DROP TABLE pending_changes');
    }
}
