<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #2815 — durable import progress.
 *
 * Until now the only trace a running import left in the database was its status
 * and the three outcome counters, and none of them answers "how far along is
 * it?": a re-import that only updates rows leaves `success_count` at 0 for its
 * entire run. The sessions list read that as "nothing has happened" while the
 * worker sat at 100% CPU for twenty minutes, and a hung run was indistinguishable
 * from a slow one.
 *
 * `processed_rows` is written with every flushed chunk; `progress_updated_at`
 * says when it last moved, which is what separates slow from stuck.
 *
 * Additive + idempotent (guarded on information_schema) so re-runs and
 * drift-healed databases are no-ops. In-flight sessions default to 0 / NULL,
 * i.e. exactly what the pre-#2815 code recorded for them.
 */
final class Version20260811090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add processed_rows + progress_updated_at to import_sessions (durable progress, #2815)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM information_schema.columns
                        WHERE table_schema = 'public'
                          AND table_name = 'import_sessions'
                          AND column_name = 'processed_rows'
                    ) THEN
                        ALTER TABLE import_sessions
                            ADD COLUMN processed_rows INTEGER NOT NULL DEFAULT 0;
                    END IF;

                    IF NOT EXISTS (
                        SELECT 1 FROM information_schema.columns
                        WHERE table_schema = 'public'
                          AND table_name = 'import_sessions'
                          AND column_name = 'progress_updated_at'
                    ) THEN
                        ALTER TABLE import_sessions
                            ADD COLUMN progress_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL;
                    END IF;
                END
                $$;
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_sessions DROP COLUMN IF EXISTS processed_rows');
        $this->addSql('ALTER TABLE import_sessions DROP COLUMN IF EXISTS progress_updated_at');
    }
}
