<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * WFL-P4-01 (#2428) — `workflow_tasks`: assignable work items of the
 * editorial loop (review / fix / request_unpublish / custom). Work
 * reaches PEOPLE (inRiver/Akeneo pattern) instead of waiting for
 * someone to browse states. `object_id` is a bare UUID (ADR-0015);
 * tenant RLS + Super Admin bypass like every WFL table.
 */
final class Version20260711120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'WFL-P4-01: workflow_tasks table + tenant RLS policies';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE workflow_tasks (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                object_id UUID DEFAULT NULL,
                title VARCHAR(255) NOT NULL,
                type VARCHAR(32) NOT NULL,
                assignee_user_id UUID DEFAULT NULL,
                assignee_role_code VARCHAR(64) DEFAULT NULL,
                due_date DATE DEFAULT NULL,
                status VARCHAR(16) DEFAULT 'open' NOT NULL,
                created_by UUID DEFAULT NULL,
                resolved_by UUID DEFAULT NULL,
                resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                comment TEXT DEFAULT NULL,
                context JSONB DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX workflow_tasks_tenant_status_due_idx ON workflow_tasks (tenant_id, status, due_date)');
        $this->addSql('CREATE INDEX workflow_tasks_tenant_assignee_idx ON workflow_tasks (tenant_id, assignee_user_id)');
        $this->addSql('CREATE INDEX workflow_tasks_tenant_object_idx ON workflow_tasks (tenant_id, object_id)');
        $this->addSql(
            'ALTER TABLE workflow_tasks ADD CONSTRAINT fk_workflow_tasks_tenant '
            .'FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE'
        );

        $predicate = "tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid";
        $this->addSql('ALTER TABLE workflow_tasks ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE workflow_tasks FORCE ROW LEVEL SECURITY');
        $this->addSql(\sprintf(
            'CREATE POLICY tenant_isolation_workflow_tasks ON workflow_tasks USING (%s) WITH CHECK (%s)',
            $predicate,
            $predicate,
        ));
        $this->addSql(
            'CREATE POLICY super_admin_bypass_workflow_tasks ON workflow_tasks '
            ."USING (current_setting('app.is_super_admin', true) = 'true') "
            ."WITH CHECK (current_setting('app.is_super_admin', true) = 'true')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS super_admin_bypass_workflow_tasks ON workflow_tasks');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation_workflow_tasks ON workflow_tasks');
        $this->addSql('ALTER TABLE workflow_tasks NO FORCE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE workflow_tasks DISABLE ROW LEVEL SECURITY');
        $this->addSql('DROP TABLE workflow_tasks');
    }
}
