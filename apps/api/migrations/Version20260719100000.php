<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * APIC / #2634 — RPC-style outbound support:
 *
 * 1. `integration_remote_endpoints.request_format` (json|form) so RPC APIs
 *    (BaseLinker's connector.php) can receive form-urlencoded pushes.
 * 2. `integration_field_mappings.endpoint_id` — optional per-operation mapping
 *    scope (payload shape differs per RPC method); null = all endpoints.
 *    ON DELETE CASCADE so an orphaned scope never silently widens back to
 *    every endpoint.
 *
 * Expand-only with defaults, so existing rows keep the current behaviour.
 * Defensive/idempotent (guarded on information_schema) so re-runs and
 * drift-healed databases are no-ops.
 */
final class Version20260719100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add request_format to integration_remote_endpoints + endpoint_id scope to integration_field_mappings (RPC-style outbound, #2634)';
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
                          AND column_name = 'request_format'
                    ) THEN
                        ALTER TABLE integration_remote_endpoints
                            ADD COLUMN request_format VARCHAR(16) DEFAULT 'json' NOT NULL;
                    END IF;

                    IF NOT EXISTS (
                        SELECT 1 FROM information_schema.columns
                        WHERE table_schema = 'public'
                          AND table_name = 'integration_field_mappings'
                          AND column_name = 'endpoint_id'
                    ) THEN
                        ALTER TABLE integration_field_mappings
                            ADD COLUMN endpoint_id UUID DEFAULT NULL;
                        -- Doctrine-generated names (crc32 scheme) so schema
                        -- comparison stays drift-free.
                        ALTER TABLE integration_field_mappings
                            ADD CONSTRAINT "FK_73B57821AF7E36"
                                FOREIGN KEY (endpoint_id)
                                REFERENCES integration_remote_endpoints (id)
                                ON DELETE CASCADE
                                NOT DEFERRABLE INITIALLY IMMEDIATE;
                        CREATE INDEX "IDX_73B57821AF7E36"
                            ON integration_field_mappings (endpoint_id);
                    END IF;
                END
                $$;
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE integration_field_mappings DROP COLUMN IF EXISTS endpoint_id');
        $this->addSql('ALTER TABLE integration_remote_endpoints DROP COLUMN IF EXISTS request_format');
    }
}
