<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * XMLF-P3-06 (#1924) — public feed URL pull telemetry (ADR-0023 §6.9).
 *
 * `feed_pull_stats` counts hits on the public pull URL per feed per hour
 * bucket (UPSERT increment — one row per feed per hour, bounded growth,
 * no per-hit event rows). The hub KPI "Pobrania · 24h" and the sparkline
 * aggregate over the last 24 buckets. `feed_profiles.last_pulled_at`
 * answers "is the crawler alive?" in the feed monitor.
 *
 * The table is mapped as {@see \App\Export\Feed\Domain\Entity\FeedPullStat}
 * so the test kernel's schema build (`doctrine:schema:create` from ORM
 * metadata) knows it — but it is written with a raw UPSERT on the public
 * request path and only ever read as an aggregate, never hydrated. The RLS
 * policies and the ON DELETE CASCADE FK below exist only here (the FeedRun
 * precedent: DB-level FK deliberately absent from the mapping).
 *
 * Tenant-scoped with Postgres RLS (the pull endpoint sets the
 * `app.current_tenant` GUC from the URL before any query) + the Super
 * Admin bypass flag, mirroring `feed_profiles`.
 */
final class Version20260701140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'XMLF-P3-06: feed_pull_stats hourly counters + feed_profiles.last_pulled_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feed_profiles ADD last_pulled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE feed_pull_stats (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                feed_id UUID NOT NULL,
                bucket_start TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                pull_count INT DEFAULT 0 NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        // The UPSERT conflict target — one counter row per feed per hour.
        $this->addSql('CREATE UNIQUE INDEX feed_pull_stats_bucket_uniq ON feed_pull_stats (tenant_id, feed_id, bucket_start)');

        $this->addSql(
            'ALTER TABLE feed_pull_stats ADD CONSTRAINT fk_feed_pull_stats_tenant '
            .'FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE'
        );
        // Deleting a feed drops its telemetry — orphan counters would leak
        // into the tenant-wide 24h aggregate.
        $this->addSql(
            'ALTER TABLE feed_pull_stats ADD CONSTRAINT fk_feed_pull_stats_feed '
            .'FOREIGN KEY (feed_id) REFERENCES feed_profiles (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE'
        );

        $this->addSql('ALTER TABLE feed_pull_stats ENABLE ROW LEVEL SECURITY');
        $this->addSql(
            'CREATE POLICY tenant_isolation_feed_pull_stats ON feed_pull_stats '
            ."USING (tenant_id = current_setting('app.current_tenant', true)::uuid)"
        );
        $this->addSql(
            'CREATE POLICY super_admin_bypass_feed_pull_stats ON feed_pull_stats '
            ."USING (current_setting('app.is_super_admin', true) = 'true')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS super_admin_bypass_feed_pull_stats ON feed_pull_stats');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation_feed_pull_stats ON feed_pull_stats');
        $this->addSql('DROP TABLE feed_pull_stats');
        $this->addSql('ALTER TABLE feed_profiles DROP last_pulled_at');
    }
}
