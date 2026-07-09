<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CPDF-P1-01 — `catalog_profiles` + `catalog_runs`: printable PDF product
 * catalog definitions and the audit of their generations (ADR-0027 §6.3).
 * Template + branding + field mappings + filter + scope + renderer + URL token
 * + cache metadata.
 *
 * Both tenant-scoped with Postgres RLS (defence in depth on top of the Doctrine
 * TenantFilter) using the `app.current_tenant` GUC set per request, plus the
 * Super Admin bypass flag. catalog_runs carries its own tenant_id plus a
 * CASCADE FK to catalog_profiles.
 */
final class Version20260709120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CPDF-P1-01: catalog_profiles + catalog_runs tables + tenant RLS policies';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_profiles (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                code VARCHAR(64) NOT NULL,
                name VARCHAR(255) NOT NULL,
                template_kind VARCHAR(32) NOT NULL,
                object_type_id UUID NOT NULL,
                branding JSONB DEFAULT '{}' NOT NULL,
                field_mappings JSONB DEFAULT '[]' NOT NULL,
                filter JSONB DEFAULT NULL,
                channel_id UUID DEFAULT NULL,
                publication_channel VARCHAR(64) DEFAULT NULL,
                locale VARCHAR(12) DEFAULT NULL,
                renderer VARCHAR(16) DEFAULT 'auto' NOT NULL,
                token_hash VARCHAR(255) DEFAULT NULL,
                cached_file_path TEXT DEFAULT NULL,
                cached_file_size BIGINT DEFAULT NULL,
                cached_page_count INT DEFAULT NULL,
                cached_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                status VARCHAR(16) DEFAULT 'active' NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX catalog_profiles_tenant_code_uniq ON catalog_profiles (tenant_id, code)');
        $this->addSql('CREATE INDEX catalog_profiles_tenant_idx ON catalog_profiles (tenant_id, updated_at)');

        $this->addSql(
            'ALTER TABLE catalog_profiles ADD CONSTRAINT fk_catalog_profiles_tenant '
            .'FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_runs (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                catalog_profile_id UUID NOT NULL,
                trigger VARCHAR(16) NOT NULL,
                status VARCHAR(16) NOT NULL,
                page_count INT DEFAULT NULL,
                byte_size BIGINT DEFAULT NULL,
                item_count INT DEFAULT NULL,
                duration_ms INT DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX catalog_runs_profile_idx ON catalog_runs (catalog_profile_id, started_at)');
        $this->addSql(
            'ALTER TABLE catalog_runs ADD CONSTRAINT fk_catalog_runs_tenant '
            .'FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE'
        );
        $this->addSql(
            'ALTER TABLE catalog_runs ADD CONSTRAINT fk_catalog_runs_profile '
            .'FOREIGN KEY (catalog_profile_id) REFERENCES catalog_profiles (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE'
        );

        // ── Row-Level Security (W1-1 FORCE pattern, mirrors Version20260705090000) ──
        // NULLIF predicate tolerates an unset/blank GUC; WITH CHECK guards writes
        // too; the explicit pim_app GRANT survives pg_restore privilege loss so
        // the runtime role can read/write under the privilege split (#2177).
        $predicate = "tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid";

        foreach (['catalog_profiles', 'catalog_runs'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', $table));
            $this->addSql(sprintf('ALTER TABLE %s FORCE ROW LEVEL SECURITY', $table));
            $this->addSql(sprintf(
                'CREATE POLICY tenant_isolation_%s ON %s USING (%s) WITH CHECK (%s)',
                $table,
                $table,
                $predicate,
                $predicate,
            ));
            $this->addSql(sprintf(
                'CREATE POLICY super_admin_bypass_%s ON %s '
                ."USING (current_setting('app.is_super_admin', true) = 'true') "
                ."WITH CHECK (current_setting('app.is_super_admin', true) = 'true')",
                $table,
                $table,
            ));
            $this->addSql(sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON %s TO pim_app', $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['catalog_runs', 'catalog_profiles'] as $table) {
            $this->addSql(sprintf('DROP POLICY IF EXISTS super_admin_bypass_%s ON %s', $table, $table));
            $this->addSql(sprintf('DROP POLICY IF EXISTS tenant_isolation_%s ON %s', $table, $table));
            $this->addSql(sprintf('ALTER TABLE %s NO FORCE ROW LEVEL SECURITY', $table));
            $this->addSql(sprintf('ALTER TABLE %s DISABLE ROW LEVEL SECURITY', $table));
        }
        $this->addSql('DROP TABLE catalog_runs');
        $this->addSql('DROP TABLE catalog_profiles');
    }
}
