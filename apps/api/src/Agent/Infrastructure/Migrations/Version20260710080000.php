<?php

declare(strict_types=1);

namespace AgentMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AICG-P1-01 (#2327, ADR-0030) — content_recipes: the reusable "how to
 * write" configuration for the content-generation tools.
 *
 * Module-namespace migration (ADR-0024 a): ships inside src/Agent and
 * disappears with it. TenantScoped with Postgres RLS (FORCE, NULLIF
 * predicate + WITH CHECK, super-admin bypass) — same pattern as
 * agent_runs (AGENT-P1-01). `object_type_id` / `brand_voice_id` are
 * bare UUIDs (ADR-0015), deliberately without cross-BC FKs.
 */
final class Version20260710080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AICG-P1-01: content_recipes + tenant RLS policies';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE content_recipes (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                code VARCHAR(128) NOT NULL,
                name VARCHAR(255) NOT NULL,
                object_type_id UUID DEFAULT NULL,
                applies_to JSONB NOT NULL,
                target_attribute VARCHAR(128) NOT NULL,
                source_attributes JSONB NOT NULL,
                constraints_config JSONB NOT NULL,
                tone_hint VARCHAR(255) DEFAULT NULL,
                brand_voice_id UUID DEFAULT NULL,
                is_built_in BOOLEAN DEFAULT false NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_content_recipes_code ON content_recipes (tenant_id, code)');
        $this->addSql('CREATE INDEX idx_content_recipes_object_type ON content_recipes (tenant_id, object_type_id)');
        $this->addSql(
            'ALTER TABLE content_recipes ADD CONSTRAINT fk_content_recipes_tenant '
            .'FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE'
        );

        $predicate = "tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid";
        $this->addSql('ALTER TABLE content_recipes ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE content_recipes FORCE ROW LEVEL SECURITY');
        $this->addSql(\sprintf(
            'CREATE POLICY tenant_isolation_content_recipes ON content_recipes USING (%s) WITH CHECK (%s)',
            $predicate,
            $predicate,
        ));
        $this->addSql(
            'CREATE POLICY super_admin_bypass_content_recipes ON content_recipes '
            ."USING (current_setting('app.is_super_admin', true) = 'true') "
            ."WITH CHECK (current_setting('app.is_super_admin', true) = 'true')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS super_admin_bypass_content_recipes ON content_recipes');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation_content_recipes ON content_recipes');
        $this->addSql('ALTER TABLE content_recipes NO FORCE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE content_recipes DISABLE ROW LEVEL SECURITY');
        $this->addSql('DROP TABLE content_recipes');
    }
}
