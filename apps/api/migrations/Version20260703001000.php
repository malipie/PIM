<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AGENT-P8-05 (#1987) — configurable agent autonomy per role
 * (off | read_only | propose). Default 'propose' preserves the current
 * behaviour: full tool surface with write/schema through approval.
 */
final class Version20260703001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Add roles.agent_autonomy (off|read_only|propose, default 'propose')";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE roles ADD agent_autonomy VARCHAR(16) DEFAULT 'propose' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE roles DROP agent_autonomy');
    }
}
