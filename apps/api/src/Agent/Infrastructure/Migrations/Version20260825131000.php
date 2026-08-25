<?php

declare(strict_types=1);

namespace AgentMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** #2998 — durable, tenant-scoped agent latency and prompt-cache telemetry. */
final class Version20260825131000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agent queue/LLM/TTFT/cache telemetry on agent_runs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE agent_runs
                ADD cache_read_tokens INT DEFAULT 0 NOT NULL,
                ADD cache_creation_tokens INT DEFAULT 0 NOT NULL,
                ADD llm_calls INT DEFAULT 0 NOT NULL,
                ADD llm_duration_ms INT DEFAULT 0 NOT NULL,
                ADD llm_ttft_ms INT DEFAULT 0 NOT NULL,
                ADD queue_delay_ms INT DEFAULT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE agent_runs
                DROP cache_read_tokens,
                DROP cache_creation_tokens,
                DROP llm_calls,
                DROP llm_duration_ms,
                DROP llm_ttft_ms,
                DROP queue_delay_ms
            SQL);
    }
}
