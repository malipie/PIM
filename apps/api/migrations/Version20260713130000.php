<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PTR-01 — merge column views into smart presets: add the `columns` JSONB
 * column to smart_filter_presets (a snapshot of GridColumnOverride[] captured
 * when saving a custom preset; null = filter-only preset, legacy shape).
 * Defensive/idempotent (guarded on information_schema) so re-runs and
 * drift-healed databases are no-ops.
 */
final class Version20260713130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add columns JSONB snapshot to smart_filter_presets (merge column views into presets, PTR-01)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM information_schema.columns
                        WHERE table_schema = 'public'
                          AND table_name = 'smart_filter_presets'
                          AND column_name = 'columns'
                    ) THEN
                        ALTER TABLE smart_filter_presets ADD COLUMN columns JSONB DEFAULT NULL;
                    END IF;
                END
                $$;
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE smart_filter_presets DROP COLUMN IF EXISTS columns');
    }
}
