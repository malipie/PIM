<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DP-07 (#2037, ADR-0025) — ObjectType-level cross-field validation rules.
 *
 * List-shaped JSONB (`[]` default) holding `compare` and `require_when`
 * rules; shape is guarded at the domain edge (CrossFieldRules::fromArray),
 * the column itself stays schemaless like completeness_rules.
 */
final class Version20260703170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add object_types.validation_rules JSONB (cross-field rules, ADR-0025)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE object_types ADD validation_rules JSONB DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE object_types DROP validation_rules');
    }
}
