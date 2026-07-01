<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * XMLF-P1-02 (#1909) — `feed_runs` + `feed_run_logs`: audit of feed
 * regenerations and their per-product/slot health log (ADR-0023 §6.2).
 *
 * Both tenant-scoped with Postgres RLS (defence in depth on top of the
 * Doctrine TenantFilter). feed_run_logs carries its own tenant_id like
 * import_logs (IMP2-2.5), plus a CASCADE FK to feed_runs.
 */
final class Version20260701130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'XMLF-P1-02: feed_runs + feed_run_logs tables + tenant RLS policies';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE feed_runs (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                feed_profile_id UUID NOT NULL,
                trigger VARCHAR(16) NOT NULL,
                status VARCHAR(16) NOT NULL,
                item_count INT DEFAULT 0 NOT NULL,
                skipped_count INT DEFAULT 0 NOT NULL,
                warning_count INT DEFAULT 0 NOT NULL,
                file_path TEXT DEFAULT NULL,
                file_size_bytes BIGINT DEFAULT NULL,
                duration_ms INT DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX feed_runs_profile_idx ON feed_runs (feed_profile_id, started_at)');
        $this->addSql(
            'ALTER TABLE feed_runs ADD CONSTRAINT fk_feed_runs_tenant '
            .'FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE'
        );
        $this->addSql(
            'ALTER TABLE feed_runs ADD CONSTRAINT fk_feed_runs_profile '
            .'FOREIGN KEY (feed_profile_id) REFERENCES feed_profiles (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE feed_run_logs (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                feed_run_id UUID NOT NULL,
                level VARCHAR(8) NOT NULL,
                object_sku VARCHAR(255) DEFAULT NULL,
                slot VARCHAR(64) DEFAULT NULL,
                message TEXT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX feed_run_logs_run_idx ON feed_run_logs (feed_run_id)');
        $this->addSql(
            'ALTER TABLE feed_run_logs ADD CONSTRAINT fk_feed_run_logs_tenant '
            .'FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE'
        );
        $this->addSql(
            'ALTER TABLE feed_run_logs ADD CONSTRAINT fk_feed_run_logs_run '
            .'FOREIGN KEY (feed_run_id) REFERENCES feed_runs (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE'
        );

        foreach (['feed_runs', 'feed_run_logs'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', $table));
            $this->addSql(sprintf(
                "CREATE POLICY tenant_isolation_%s ON %s USING (tenant_id = current_setting('app.current_tenant', true)::uuid)",
                $table,
                $table,
            ));
            $this->addSql(sprintf(
                "CREATE POLICY super_admin_bypass_%s ON %s USING (current_setting('app.is_super_admin', true) = 'true')",
                $table,
                $table,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS super_admin_bypass_feed_run_logs ON feed_run_logs');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation_feed_run_logs ON feed_run_logs');
        $this->addSql('DROP POLICY IF EXISTS super_admin_bypass_feed_runs ON feed_runs');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation_feed_runs ON feed_runs');
        $this->addSql('DROP TABLE feed_run_logs');
        $this->addSql('DROP TABLE feed_runs');
    }
}
