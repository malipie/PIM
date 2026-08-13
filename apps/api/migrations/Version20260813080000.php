<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Identity\Infrastructure\Doctrine\RoleAssignmentBackfill;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #2832 / ADR-0034 — one source of truth for role assignments.
 *
 * Role grants lived in two tables that only ever met inside one SQL query:
 * the Sprint-0 `user_roles` M2M and `user_role_assignments`, which is the
 * one that can carry locale / channel / attribute-group scope. Whoever was
 * written where depended on which flow created the user, so a member added
 * through an invitation displayed as "no roles" in the users list while
 * holding a working role, and a locale-scoped grant could be widened by its
 * own duplicate row on the scope-less side (AUD-029).
 *
 * This copies every legacy row that has no counterpart yet. Scopes default
 * to empty — the legacy table never had them, and empty means "no
 * restriction", which is exactly what those grants were.
 *
 * `user_roles` is deliberately LEFT IN PLACE. The application stops reading
 * and writing it as of this release, but the rows stay as a safety net: if
 * anything failed to copy, the record is still there and recoverable without
 * a restore. Dropping the table is the contract step, a separate ticket,
 * after production confirms nobody lost access.
 *
 * Idempotent: re-running inserts nothing (NOT EXISTS on the unique pair).
 */
final class Version20260813080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Copy legacy user_roles grants into user_role_assignments (ADR-0034 expand step).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(RoleAssignmentBackfill::SQL);
    }

    public function down(Schema $schema): void
    {
        // Intentionally not reversible: the rows inserted here are
        // indistinguishable from assignments created normally after the
        // release, so deleting "the copied ones" would take live grants with
        // them. The legacy table still holds the original record.
        $this->throwIrreversibleMigrationException(
            'ADR-0034 expand step is not reversible; legacy user_roles rows were left intact.',
        );
    }
}
