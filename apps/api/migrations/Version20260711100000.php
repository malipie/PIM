<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * WFL-P1-04 (#2418) — per-ObjectType completeness gate on publishing
 * transitions (ADR-0029 pillar 6). NULL = gate off (the benchmark
 * default: no PIM hard-blocks publication out of the box). Shape:
 * `{enabled, min_completeness_pct, scope: global|per_channel,
 * channels?}` — authoritative contract in docs/api/jsonb-schemas.md §6.
 */
final class Version20260711100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'WFL-P1-04: object_types.workflow_publish_gate JSONB (completeness gate config)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE object_types ADD workflow_publish_gate JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE object_types DROP workflow_publish_gate');
    }
}
