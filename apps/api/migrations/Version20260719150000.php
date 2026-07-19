<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * APIC / #2636 — remote-id capture on write endpoints: `response_id_selector`
 * (JSONPath into the write response) + `response_id_attribute` (PIM attribute
 * code the captured id is written to). Expand-only nullable columns, so
 * existing endpoints keep the current no-capture behaviour. Defensive /
 * idempotent (guarded on information_schema).
 */
final class Version20260719150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add response_id_selector + response_id_attribute to integration_remote_endpoints (remote-id capture, #2636)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM information_schema.columns
                        WHERE table_schema = 'public'
                          AND table_name = 'integration_remote_endpoints'
                          AND column_name = 'response_id_selector'
                    ) THEN
                        ALTER TABLE integration_remote_endpoints
                            ADD COLUMN response_id_selector VARCHAR(512) DEFAULT NULL,
                            ADD COLUMN response_id_attribute VARCHAR(255) DEFAULT NULL;
                    END IF;
                END
                $$;
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE integration_remote_endpoints DROP COLUMN IF EXISTS response_id_selector');
        $this->addSql('ALTER TABLE integration_remote_endpoints DROP COLUMN IF EXISTS response_id_attribute');
    }
}
