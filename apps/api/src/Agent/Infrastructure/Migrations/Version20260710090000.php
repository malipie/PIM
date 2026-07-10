<?php

declare(strict_types=1);

namespace AgentMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AICG-P1-02 (#2328, ADR-0030 decision B) — brand_voice_profiles: the
 * tenant's brand voice injected into content-generation prompts.
 *
 * Module-namespace migration (ADR-0024 a). TenantScoped with Postgres
 * RLS (FORCE, NULLIF predicate + WITH CHECK, super-admin bypass) — same
 * pattern as content_recipes (AICG-P1-01). The partial unique index
 * guarantees at most one is_default=true profile per tenant.
 */
final class Version20260710090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AICG-P1-02: brand_voice_profiles + tenant RLS policies + single-default guard';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE brand_voice_profiles (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                name VARCHAR(255) NOT NULL,
                tone TEXT NOT NULL,
                glossary JSONB NOT NULL,
                banned_words JSONB NOT NULL,
                examples JSONB NOT NULL,
                is_default BOOLEAN DEFAULT false NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_brand_voice_profiles_tenant ON brand_voice_profiles (tenant_id)');
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_brand_voice_profiles_default ON brand_voice_profiles (tenant_id) WHERE is_default = true'
        );
        $this->addSql(
            'ALTER TABLE brand_voice_profiles ADD CONSTRAINT fk_brand_voice_profiles_tenant '
            .'FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE'
        );

        $predicate = "tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid";
        $this->addSql('ALTER TABLE brand_voice_profiles ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE brand_voice_profiles FORCE ROW LEVEL SECURITY');
        $this->addSql(\sprintf(
            'CREATE POLICY tenant_isolation_brand_voice_profiles ON brand_voice_profiles USING (%s) WITH CHECK (%s)',
            $predicate,
            $predicate,
        ));
        $this->addSql(
            'CREATE POLICY super_admin_bypass_brand_voice_profiles ON brand_voice_profiles '
            ."USING (current_setting('app.is_super_admin', true) = 'true') "
            ."WITH CHECK (current_setting('app.is_super_admin', true) = 'true')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS super_admin_bypass_brand_voice_profiles ON brand_voice_profiles');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation_brand_voice_profiles ON brand_voice_profiles');
        $this->addSql('ALTER TABLE brand_voice_profiles NO FORCE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE brand_voice_profiles DISABLE ROW LEVEL SECURITY');
        $this->addSql('DROP TABLE brand_voice_profiles');
    }
}
