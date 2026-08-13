<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #2836 / ADR-0034 — contract step: drop the legacy `user_roles` M2M.
 *
 * {@see Version20260813080000} copied every legacy grant into
 * `user_role_assignments` and deliberately left the old table behind as a
 * safety net: if a row had failed to copy, the record would still be there
 * and recoverable without a restore.
 *
 * The net turned out to be provably empty. On production, hours after the
 * expand step shipped:
 *
 *     legacy_rows                2
 *     assignment_rows            4
 *     legacy without counterpart 0
 *
 * Nothing in the application has read or written the table since the expand
 * release — the four raw-SQL consumers found during that ticket
 * (AttributePermissionPolicy, SqlPermissionGrantees, RoleAgentAutonomyResolver,
 * DoctrineWorkflowTaskRepository) were all moved over.
 *
 * `RoleAssignmentBackfill` stays in `src/`, and that is not an oversight:
 * Version20260813080000 references its SQL constant, and a fresh database
 * still runs the whole chain — create `user_roles` (Sprint-0), backfill from
 * it, then drop it here. Deleting the class would break migrating from
 * scratch, which CI does on every run.
 */
final class Version20260813200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the legacy user_roles M2M (ADR-0034 contract step).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS user_roles');
    }

    public function down(Schema $schema): void
    {
        // Recreates the shape, never the data: the grants live in
        // user_role_assignments now, and copying them back would invent
        // legacy rows for assignments that were never in the legacy table
        // (everything created after the expand release). A rollback that
        // needs the old rows needs the backup from before the drop.
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS user_roles (
                user_id UUID NOT NULL,
                role_id UUID NOT NULL,
                PRIMARY KEY (user_id, role_id)
            )
            SQL);
    }
}
