<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * XMLF-P1-01 (#1908) — `feed_profiles`: outgoing product XML feed definitions
 * (ADR-0023 §6.2). Template + descriptor + field mappings + filter + scope +
 * schedule + URL token + cache metadata.
 *
 * Tenant-scoped with Postgres RLS (defence in depth on top of the Doctrine
 * TenantFilter) using the `app.current_tenant` GUC set per request, plus the
 * Super Admin bypass flag.
 */
final class Version20260701120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'XMLF-P1-01: feed_profiles table + tenant RLS policies';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE feed_profiles (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                code VARCHAR(64) NOT NULL,
                name VARCHAR(255) NOT NULL,
                template_kind VARCHAR(32) NOT NULL,
                object_type_id UUID NOT NULL,
                descriptor JSONB DEFAULT '{}' NOT NULL,
                field_mappings JSONB DEFAULT '[]' NOT NULL,
                filter JSONB DEFAULT NULL,
                channel_id UUID DEFAULT NULL,
                publication_channel VARCHAR(64) DEFAULT NULL,
                locale VARCHAR(12) DEFAULT NULL,
                currency VARCHAR(3) DEFAULT NULL,
                schedule_cron VARCHAR(64) DEFAULT NULL,
                delivery JSONB DEFAULT '{}' NOT NULL,
                token_hash VARCHAR(255) DEFAULT NULL,
                status VARCHAR(16) DEFAULT 'active' NOT NULL,
                validation_policy VARCHAR(24) DEFAULT 'skip_invalid' NOT NULL,
                last_run_id UUID DEFAULT NULL,
                cached_file_path TEXT DEFAULT NULL,
                cached_file_size BIGINT DEFAULT NULL,
                cached_item_count INT DEFAULT NULL,
                cached_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX feed_profiles_tenant_code_uniq ON feed_profiles (tenant_id, code)');
        $this->addSql('CREATE INDEX feed_profiles_tenant_idx ON feed_profiles (tenant_id, updated_at)');
        // NOTE: the partial scheduler-sweep index (status WHERE schedule_cron IS
        // NOT NULL AND status='active') lands with the scheduler in XMLF-P4-01,
        // where it is used — a partial index is not expressible in the ORM
        // mapping, so adding it here would make doctrine:schema:validate drift.

        $this->addSql(
            'ALTER TABLE feed_profiles ADD CONSTRAINT fk_feed_profiles_tenant '
            .'FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE'
        );

        $this->addSql('ALTER TABLE feed_profiles ENABLE ROW LEVEL SECURITY');
        $this->addSql(
            'CREATE POLICY tenant_isolation_feed_profiles ON feed_profiles '
            ."USING (tenant_id = current_setting('app.current_tenant', true)::uuid)"
        );
        $this->addSql(
            'CREATE POLICY super_admin_bypass_feed_profiles ON feed_profiles '
            ."USING (current_setting('app.is_super_admin', true) = 'true')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS super_admin_bypass_feed_profiles ON feed_profiles');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation_feed_profiles ON feed_profiles');
        $this->addSql('DROP TABLE feed_profiles');
    }
}
