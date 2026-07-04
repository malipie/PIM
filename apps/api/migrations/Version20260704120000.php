<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * GOLIVE #2136 (agent live-smoke finding) — grant + self-heal
 * `processed_messages` for the runtime role.
 *
 * The IdempotencyMiddleware runs on every worker-consumed message and
 * INSERTs into `processed_messages`. The agent async loop routes
 * AgentRunMessage to the worker-drained `import` transport, so it is the
 * first path that actually exercises that INSERT as `pim_app` on the
 * operator's stack — nothing else consumed a worker message under RLS
 * before the agent BYOK key was live. It surfaced two gaps:
 *
 *   1. The original table migration (Version20260429170000) predates the
 *      #2177 grant pattern and issued NO `GRANT ... TO pim_app`. The W1-1
 *      default privileges normally cover owner-created tables, but a
 *      pg_restore drops them (known quirk, see Version20260704050000) — so
 *      the runtime role could be left unable to INSERT.
 *   2. On a stack where the table went missing entirely (restore/reset that
 *      kept the migration_versions row), the middleware's self-heal tries
 *      `CREATE TABLE` as `pim_app` and dies with 42501 (permission denied
 *      for schema public) — the runtime role has no DDL by design. The
 *      table can only be (re)created by the owner, i.e. here.
 *
 * This migration is idempotent belt-and-braces for both: `CREATE TABLE IF
 * NOT EXISTS` recreates a vanished table, and the explicit grant is safe to
 * repeat. DDL is 1:1 with Version20260429170000 + IdempotencyMiddleware's
 * self-heal. Infra table: NO tenant_id, NO RLS (global messenger
 * bookkeeping, same class as messenger_messages).
 */
final class Version20260704120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Grant processed_messages to pim_app + recreate if missing — the agent worker path is the first to INSERT as the runtime role (#2136)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS processed_messages (
                message_id UUID PRIMARY KEY,
                handler_class VARCHAR(255) NOT NULL,
                processed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS processed_messages_handler_idx ON processed_messages (handler_class)');
        $this->addSql('CREATE INDEX IF NOT EXISTS processed_messages_processed_at_idx ON processed_messages (processed_at)');
        $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON processed_messages TO pim_app');
    }

    public function down(Schema $schema): void
    {
        // Non-destructive: the table predates this migration (created by
        // Version20260429170000). Only the grant is this migration's to
        // revoke; dropping the table would break the earlier migration's
        // invariant. Revoke is best-effort (role may not exist on a
        // single-role sandbox).
        $this->addSql('REVOKE SELECT, INSERT, UPDATE, DELETE ON processed_messages FROM pim_app');
    }
}
