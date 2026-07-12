<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #2513 — configurable workflow approver: add the definition-level
 * `reviewer` JSONB column ({role_code}|{user_id}|null). Null = the
 * built-in reviewer role. Defensive/idempotent (guarded on
 * information_schema) so re-runs and drift-healed databases are no-ops.
 */
final class Version20260712100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reviewer JSONB column to workflow_definitions (configurable approver, #2513)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = 'public'
                      AND table_name = 'workflow_definitions'
                      AND column_name = 'reviewer'
                ) THEN
                    ALTER TABLE workflow_definitions ADD COLUMN reviewer JSONB DEFAULT NULL;
                END IF;
            END
            $$;
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_definitions DROP COLUMN IF EXISTS reviewer');
    }
}
