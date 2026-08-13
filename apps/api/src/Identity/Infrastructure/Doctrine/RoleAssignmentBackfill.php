<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

/**
 * ADR-0034 (#2832) — the expand-step backfill statement, kept in `src` so
 * both the migration and its regression test use the very same SQL. The
 * `migrations/` directory is loaded by the bundle rather than PSR-4
 * autoloaded, so a constant living on the migration class is unreachable
 * from tests — and a copy of the statement in the test would be free to
 * drift away from the one that actually runs.
 *
 * Copies every legacy `user_roles` grant that has no counterpart yet.
 * Scopes default to empty: the legacy table never had scope columns, and
 * empty means "no restriction", which is exactly what those grants were.
 *
 * Guarded on information_schema so a database provisioned after the legacy
 * table was dropped is a no-op rather than an error, and idempotent through
 * the NOT EXISTS on the (user, role) pair.
 */
final class RoleAssignmentBackfill
{
    public const string SQL = <<<'SQL'
        INSERT INTO user_role_assignments (
            id, user_id, role_id, locale_scope, channel_scope, attribute_group_scope, assigned_at
        )
        SELECT
            gen_random_uuid(),
            ur.user_id,
            ur.role_id,
            '[]'::json,
            '[]'::json,
            '[]'::json,
            NOW()
        FROM user_roles ur
        WHERE EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'user_roles')
          AND NOT EXISTS (
              SELECT 1 FROM user_role_assignments ura
              WHERE ura.user_id = ur.user_id
                AND ura.role_id = ur.role_id
          )
        SQL;
}
