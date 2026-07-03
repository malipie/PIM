<?php

declare(strict_types=1);

namespace AgentMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #2155 — narrow "1 active run per user" to the GENUINELY EXECUTING
 * statuses. The original partial unique index (Version20260702170000)
 * covered planning + awaiting_input + awaiting_approval + committing, so
 * a run parked for the human (awaiting a chat reply, or a plan sitting in
 * the approval inbox) occupied the single "active" slot and blocked a new
 * conversation at the DB level — a dead-end when a parked run got stuck.
 *
 * Parked runs cost nothing (the LLM is not running) and live in the
 * inbox/history for their own accept/reject/resume decision, so they no
 * longer count as active. Only planning (loop running) and committing
 * (writing the approved batch) block a concurrent start. Mirrors
 * {@see \App\Agent\Application\Run\AgentRunStarter::hasActiveRun}.
 */
final class Version20260703130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#2155: narrow "one active run per user" index to executing statuses (planning, committing)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_agent_runs_active_per_user');
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_agent_runs_active_per_user ON agent_runs (tenant_id, user_id) '
            ."WHERE status IN ('planning', 'committing')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_agent_runs_active_per_user');
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_agent_runs_active_per_user ON agent_runs (tenant_id, user_id) '
            ."WHERE status IN ('planning', 'awaiting_input', 'awaiting_approval', 'committing')"
        );
    }
}
