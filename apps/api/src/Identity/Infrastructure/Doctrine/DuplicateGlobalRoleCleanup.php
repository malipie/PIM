<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

/**
 * #2837 — the statement that removes global copies of tenant-facing roles.
 *
 * Lives in `src/` for the same reason as {@see RoleAssignmentBackfill}: the
 * `migrations/` directory is loaded by the bundle rather than PSR-4
 * autoloaded, so a test cannot reach a constant declared on the migration,
 * and a second copy of the SQL in the test would be free to drift from the
 * one that runs.
 *
 * Two guards, both deliberate:
 *
 *  - `tenant_id IS NULL` plus an EXISTS on a per-tenant row with the same
 *    code — only genuine duplicates go, never a role that exists solely
 *    globally (that is how `super_admin` and `platform_operator` survive).
 *  - `NOT EXISTS` on `user_role_assignments` — a global role somebody
 *    actually holds stays put. Deleting it would revoke access, which has
 *    to be a decision, not a migration side effect.
 */
final class DuplicateGlobalRoleCleanup
{
    public const string SQL = <<<'SQL'
        DELETE FROM roles global_role
        WHERE global_role.tenant_id IS NULL
          AND EXISTS (
              SELECT 1 FROM roles tenant_role
              WHERE tenant_role.code = global_role.code
                AND tenant_role.tenant_id IS NOT NULL
          )
          AND NOT EXISTS (
              SELECT 1 FROM user_role_assignments ura
              WHERE ura.role_id = global_role.id
          )
        SQL;
}
