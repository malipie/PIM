<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #2667 — outbound value-source scope: add `source_channel` (channel code) and
 * `source_locale` (SHORT locale code) to sync bindings. Both are loose
 * references (no FK, like `object_type_id` — ADR-0022 keeps cross-BC coupling
 * to Contracts only); null = push global values (backward-compat). Defensive/
 * idempotent (guarded on information_schema) so re-runs and drift-healed
 * databases are no-ops.
 */
final class Version20260720100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add source_channel + source_locale columns to integration_sync_bindings (outbound value scope, #2667)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM information_schema.columns
                        WHERE table_schema = 'public'
                          AND table_name = 'integration_sync_bindings'
                          AND column_name = 'source_channel'
                    ) THEN
                        ALTER TABLE integration_sync_bindings ADD COLUMN source_channel VARCHAR(64) DEFAULT NULL;
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM information_schema.columns
                        WHERE table_schema = 'public'
                          AND table_name = 'integration_sync_bindings'
                          AND column_name = 'source_locale'
                    ) THEN
                        ALTER TABLE integration_sync_bindings ADD COLUMN source_locale VARCHAR(16) DEFAULT NULL;
                    END IF;
                END
                $$;
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE integration_sync_bindings DROP COLUMN IF EXISTS source_channel');
        $this->addSql('ALTER TABLE integration_sync_bindings DROP COLUMN IF EXISTS source_locale');
    }
}
