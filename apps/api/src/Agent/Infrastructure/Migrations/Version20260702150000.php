<?php

declare(strict_types=1);

namespace AgentMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AGENT-P1-01 (#1953) — orchestration skeleton of the agent lifecycle
 * (PRD 5.8): agent_runs / agent_messages / agent_tool_calls.
 *
 * Module-namespace migration (ADR-0024 a): ships inside src/Agent and
 * disappears with it. All three tables are TenantScoped with Postgres
 * RLS (FORCE, NULLIF predicate + WITH CHECK, super-admin bypass) —
 * same pattern as pending_changes (P0-03).
 */
final class Version20260702150000 extends AbstractMigration
{
    private const array TABLES = ['agent_runs', 'agent_messages', 'agent_tool_calls'];

    public function getDescription(): string
    {
        return 'AGENT-P1-01: agent_runs / agent_messages / agent_tool_calls + tenant RLS policies';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE agent_runs (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                user_id UUID NOT NULL,
                surface VARCHAR(16) NOT NULL,
                intent TEXT NOT NULL,
                context JSONB NOT NULL,
                status VARCHAR(32) DEFAULT 'planning' NOT NULL,
                model VARCHAR(64) DEFAULT NULL,
                pending_change_batch_id UUID DEFAULT NULL,
                bulk_operation_id UUID DEFAULT NULL,
                affected_count INT DEFAULT NULL,
                tokens_input INT DEFAULT 0 NOT NULL,
                tokens_output INT DEFAULT 0 NOT NULL,
                cost_usd NUMERIC(12, 6) DEFAULT 0 NOT NULL,
                error_message TEXT DEFAULT NULL,
                started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                approved_by UUID DEFAULT NULL,
                completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_agent_runs_tenant ON agent_runs (tenant_id, started_at)');
        $this->addSql('CREATE INDEX idx_agent_runs_user ON agent_runs (tenant_id, user_id)');
        $this->addSql('CREATE INDEX idx_agent_runs_status ON agent_runs (tenant_id, status)');
        $this->addSql(
            'ALTER TABLE agent_runs ADD CONSTRAINT fk_agent_runs_tenant '
            .'FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE agent_messages (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                agent_run_id UUID NOT NULL,
                role VARCHAR(16) NOT NULL,
                content JSONB NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_agent_messages_run ON agent_messages (agent_run_id)');
        $this->addSql(
            'ALTER TABLE agent_messages ADD CONSTRAINT fk_agent_messages_tenant '
            .'FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE'
        );
        $this->addSql(
            'ALTER TABLE agent_messages ADD CONSTRAINT fk_agent_messages_run '
            .'FOREIGN KEY (agent_run_id) REFERENCES agent_runs (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE agent_tool_calls (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                agent_run_id UUID NOT NULL,
                tool_name VARCHAR(128) NOT NULL,
                kind VARCHAR(16) NOT NULL,
                arguments JSONB NOT NULL,
                result_summary JSONB DEFAULT NULL,
                rbac_checked BOOLEAN DEFAULT false NOT NULL,
                duration_ms INT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_agent_tool_calls_run ON agent_tool_calls (agent_run_id)');
        $this->addSql(
            'ALTER TABLE agent_tool_calls ADD CONSTRAINT fk_agent_tool_calls_tenant '
            .'FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE'
        );
        $this->addSql(
            'ALTER TABLE agent_tool_calls ADD CONSTRAINT fk_agent_tool_calls_run '
            .'FOREIGN KEY (agent_run_id) REFERENCES agent_runs (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE'
        );

        $predicate = "tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid";
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', $table));
            $this->addSql(\sprintf('ALTER TABLE %s FORCE ROW LEVEL SECURITY', $table));
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation_%s ON %s USING (%s) WITH CHECK (%s)',
                $table,
                $table,
                $predicate,
                $predicate,
            ));
            $this->addSql(\sprintf(
                'CREATE POLICY super_admin_bypass_%s ON %s '
                ."USING (current_setting('app.is_super_admin', true) = 'true') "
                ."WITH CHECK (current_setting('app.is_super_admin', true) = 'true')",
                $table,
                $table,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            $this->addSql(\sprintf('DROP POLICY IF EXISTS super_admin_bypass_%s ON %s', $table, $table));
            $this->addSql(\sprintf('DROP POLICY IF EXISTS tenant_isolation_%s ON %s', $table, $table));
            $this->addSql(\sprintf('ALTER TABLE %s NO FORCE ROW LEVEL SECURITY', $table));
            $this->addSql(\sprintf('ALTER TABLE %s DISABLE ROW LEVEL SECURITY', $table));
            $this->addSql(\sprintf('DROP TABLE %s', $table));
        }
    }
}
