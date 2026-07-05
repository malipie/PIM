<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DASH-07 (#2261, ADR-0026) — composite index for the dashboard activity
 * aggregates. Both queries filter `audit_logs` by tenant AND a created_at
 * window before narrowing on action/route; the existing single-column
 * indexes (idx on tenant_id, idx on created_at, from Version20260518160000)
 * force a bitmap-AND on every dashboard hit. The composite serves the
 * range scan directly.
 */
final class Version20260705070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'DASH-07: composite index audit_logs (tenant_id, created_at) for dashboard activity aggregates.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_audit_logs_tenant_created ON audit_logs (tenant_id, created_at)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_audit_logs_tenant_created');
    }
}
