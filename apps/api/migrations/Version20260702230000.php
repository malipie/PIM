<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AGENT-P8-01 (#1983) — per-tenant opt-in for the proactive
 * data-steward scan. Lives in the core stream (tenant_agent_configs is
 * the Identity/BYOK table); default FALSE so proactivity is an explicit
 * choice.
 */
final class Version20260702230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tenant_agent_config.proactive_scan_enabled (opt-in, default false)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant_agent_configs ADD proactive_scan_enabled BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant_agent_configs DROP proactive_scan_enabled');
    }
}
