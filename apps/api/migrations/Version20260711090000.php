<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * WFL-P0-04 (#2413) — `workflow_transitions`: append-only log of the
 * `object_editorial` state machine (ADR-0029 pillar 5). Who moved which
 * object between which places, with an optional reviewer comment and a
 * JSONB context (auto_unpublish / bulk / agent_run_id flags land in
 * later WFL tickets). Deliberately a log, not versioning — product
 * versioning is a separate Faza 1 item.
 *
 * `object_id` is a bare UUID without an FK (ADR-0015 cross-BC policy:
 * the Workflow BC must not hard-couple to the Catalog schema); rows
 * outlive object deletion as audit history.
 *
 * Tenant-scoped with Postgres RLS + Super Admin bypass — same pattern
 * as pending_changes (Version20260702090000).
 */
final class Version20260711090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'WFL-P0-04: workflow_transitions log table + tenant RLS policies';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE workflow_transitions (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                object_id UUID NOT NULL,
                workflow_name VARCHAR(64) NOT NULL,
                transition VARCHAR(64) NOT NULL,
                from_place VARCHAR(32) NOT NULL,
                to_place VARCHAR(32) NOT NULL,
                actor_user_id UUID DEFAULT NULL,
                comment TEXT DEFAULT NULL,
                context JSONB DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX workflow_transitions_tenant_object_idx ON workflow_transitions (tenant_id, object_id, created_at)');
        $this->addSql('CREATE INDEX workflow_transitions_tenant_created_idx ON workflow_transitions (tenant_id, created_at)');
        $this->addSql(
            'ALTER TABLE workflow_transitions ADD CONSTRAINT fk_workflow_transitions_tenant '
            .'FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE'
        );

        $predicate = "tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid";
        $this->addSql('ALTER TABLE workflow_transitions ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE workflow_transitions FORCE ROW LEVEL SECURITY');
        $this->addSql(\sprintf(
            'CREATE POLICY tenant_isolation_workflow_transitions ON workflow_transitions USING (%s) WITH CHECK (%s)',
            $predicate,
            $predicate,
        ));
        $this->addSql(
            'CREATE POLICY super_admin_bypass_workflow_transitions ON workflow_transitions '
            ."USING (current_setting('app.is_super_admin', true) = 'true') "
            ."WITH CHECK (current_setting('app.is_super_admin', true) = 'true')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS super_admin_bypass_workflow_transitions ON workflow_transitions');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation_workflow_transitions ON workflow_transitions');
        $this->addSql('ALTER TABLE workflow_transitions NO FORCE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE workflow_transitions DISABLE ROW LEVEL SECURITY');
        $this->addSql('DROP TABLE workflow_transitions');
    }
}
