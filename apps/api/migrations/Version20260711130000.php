<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * WFL-P5-01 (#2431) — `workflow_definitions`: tenant-editable editorial
 * state machines (ADR-0029 pillar 7, behind WORKFLOW_CUSTOM_DEFINITIONS,
 * default OFF). The Pimcore lesson inverted: guards/gates live as DATA
 * (permission codes + gate config in transition entries), so a tenant
 * changes the process without a deploy.
 */
final class Version20260711130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'WFL-P5-01: workflow_definitions table + tenant RLS policies';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE workflow_definitions (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                name VARCHAR(128) NOT NULL,
                object_type_id UUID DEFAULT NULL,
                places JSONB NOT NULL,
                transitions JSONB NOT NULL,
                enabled BOOLEAN DEFAULT FALSE NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX workflow_definitions_tenant_enabled_idx ON workflow_definitions (tenant_id, enabled)');
        $this->addSql(
            'ALTER TABLE workflow_definitions ADD CONSTRAINT fk_workflow_definitions_tenant '
            .'FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE'
        );

        $predicate = "tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid";
        $this->addSql('ALTER TABLE workflow_definitions ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE workflow_definitions FORCE ROW LEVEL SECURITY');
        $this->addSql(\sprintf(
            'CREATE POLICY tenant_isolation_workflow_definitions ON workflow_definitions USING (%s) WITH CHECK (%s)',
            $predicate,
            $predicate,
        ));
        $this->addSql(
            'CREATE POLICY super_admin_bypass_workflow_definitions ON workflow_definitions '
            ."USING (current_setting('app.is_super_admin', true) = 'true') "
            ."WITH CHECK (current_setting('app.is_super_admin', true) = 'true')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS super_admin_bypass_workflow_definitions ON workflow_definitions');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation_workflow_definitions ON workflow_definitions');
        $this->addSql('ALTER TABLE workflow_definitions NO FORCE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE workflow_definitions DISABLE ROW LEVEL SECURITY');
        $this->addSql('DROP TABLE workflow_definitions');
    }
}
