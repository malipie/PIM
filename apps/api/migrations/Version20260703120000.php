<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Agent settings — per-tenant model override + prompt-caching toggle
 * on tenant_agent_configs.
 *
 * - `model` NULL = platform default (Sonnet for read/write/action,
 *   Opus for schema-ops); a concrete id pins every tool kind.
 * - `prompt_caching_enabled` default true: the agent's stable prefix
 *   (system + tool definitions) is a caching win, and on BYOK the
 *   saving accrues to the tenant.
 */
final class Version20260703120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tenant_agent_configs.model (nullable) + prompt_caching_enabled (default true)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant_agent_configs ADD model VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant_agent_configs ADD prompt_caching_enabled BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant_agent_configs DROP model');
        $this->addSql('ALTER TABLE tenant_agent_configs DROP prompt_caching_enabled');
    }
}
