<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Identity\Infrastructure\Doctrine\DuplicateGlobalRoleCleanup;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #2837 — remove the global copies of tenant-facing roles.
 *
 * Two seeders emitted overlapping role codes: `RbacSeeder` wrote global
 * rows (tenant_id NULL) from the legacy matrix, while
 * `SeedTenantPrdRolesService` writes per-tenant rows from the PRD
 * templates. `catalog_manager`, `integration_manager` and `viewer` existed
 * on both sides — indistinguishable in the panel, and NOT equivalent
 * underneath: on production `viewer` carried 11 permissions globally and
 * 17 per tenant. Which access a user got depended on which row the panel
 * happened to attach.
 *
 * Deletes only rows that nobody holds. A global role WITH an assignment is
 * left alone: removing it would revoke somebody's access, and that has to
 * be a deliberate decision rather than a side effect of a migration. On
 * production all three had zero assignments; anywhere else the leftover
 * row is a signal to look, not something to paper over.
 *
 * `role_permissions` and `user_role_assignments` cascade on the FK, so no
 * manual cleanup of junctions is needed.
 */
final class Version20260813100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop unassigned global duplicates of tenant-facing roles (ADR-0034 follow-up, #2837).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(DuplicateGlobalRoleCleanup::SQL);
    }

    public function down(Schema $schema): void
    {
        // Re-creating the rows would resurrect the ambiguity this removes,
        // and their permission sets are not recoverable from the schema.
        $this->throwIrreversibleMigrationException(
            'Global duplicates of tenant roles are removed deliberately (#2837); re-seed per tenant instead.',
        );
    }
}
