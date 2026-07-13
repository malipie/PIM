<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #2549 — rule-based outbound-sync filter: add the `outbound_filter` JSONB
 * column to sync bindings (a FilterDsl snapshot scoping which objects PIM
 * pushes to the remote; null = send all). Defensive/idempotent (guarded on
 * information_schema) so re-runs and drift-healed databases are no-ops.
 */
final class Version20260713100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add outbound_filter JSONB column to integration_sync_bindings (outbound-sync scope, #2549)';
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
                          AND column_name = 'outbound_filter'
                    ) THEN
                        ALTER TABLE integration_sync_bindings ADD COLUMN outbound_filter JSONB DEFAULT NULL;
                    END IF;
                END
                $$;
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE integration_sync_bindings DROP COLUMN IF EXISTS outbound_filter');
    }
}
