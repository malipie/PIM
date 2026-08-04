<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #2731 — media-batch idempotency: record which image batches have already been
 * accounted on the session, so a redelivery after a partially applied batch
 * cannot double-count `images_downloaded` / `images_failed` or duplicate the
 * batch's ImportLog rows. The claim is written in the SAME atomic statement as
 * the counter update, so concurrent `import` consumers cannot both claim.
 *
 * Additive + idempotent (guarded on information_schema) so re-runs and
 * drift-healed databases are no-ops. Existing rows default to an empty array,
 * which is exactly the pre-#2731 behaviour for in-flight batches.
 */
final class Version20260804120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add processed_image_batches to import_sessions (media batch idempotency, #2731)';
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
                          AND column_name = 'processed_image_batches'
                    ) THEN
                        ALTER TABLE import_sessions
                            ADD COLUMN processed_image_batches JSONB NOT NULL DEFAULT '[]'::jsonb;
                    END IF;
                END
                $$;
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_sessions DROP COLUMN IF EXISTS processed_image_batches');
    }
}
