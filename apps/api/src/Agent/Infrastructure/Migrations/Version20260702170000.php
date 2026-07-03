<?php

declare(strict_types=1);

namespace AgentMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AGENT-P1-03 (#1955) — "1 active run per user" (PRD 14.2 decision 3)
 * enforced at the database level: a partial unique index over the
 * non-terminal statuses makes concurrent starts race-proof; the app
 * layer translates the violation into a 409.
 */
final class Version20260702170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AGENT-P1-03: one active agent run per user (partial unique index)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_agent_runs_active_per_user ON agent_runs (tenant_id, user_id) '
            ."WHERE status IN ('planning', 'awaiting_input', 'awaiting_approval', 'committing')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_agent_runs_active_per_user');
    }
}
