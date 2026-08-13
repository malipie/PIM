<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Exception\LastAdminProtectionException;
use Doctrine\DBAL\Connection;

/**
 * RBAC-P3-005 (#668) — runtime guard that refuses to deactivate or
 * strip the admin role from the last user holding an administrator
 * grant on a tenant.
 *
 * "Administrator" is matched against the canonical PRD-PIM-rbac §3.2
 * role codes that grant `settings.users.manage` on the tenant tier:
 * `tenant_owner` and `admin`. `super_admin` lives on the global tier
 * and does not count toward tenant-local admin headcount.
 *
 * The guard talks to Doctrine DBAL directly because the assignment
 * table plus the `roles` lookup is a single small SQL statement and
 * avoiding the ORM keeps the hot path lean — this runs on every
 * deactivate / change_role attempt.
 */
final readonly class LastAdminGuard
{
    private const array ADMIN_ROLE_CODES = ['tenant_owner', 'admin'];

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @throws LastAdminProtectionException when deactivating $subject would
     *                                      leave the tenant without an admin
     */
    public function ensureNotLastAdmin(User $subject): void
    {
        if (!$this->isAdminInTenant($subject)) {
            return;
        }

        $adminsLeft = $this->countOtherAdminsInTenant($subject);
        if ($adminsLeft <= 0) {
            throw LastAdminProtectionException::deactivatingLastAdmin($subject);
        }
    }

    /**
     * RBAC-P5-003 (#693) — refuse a role re-assignment that strips the
     * Administrator grant from the last admin on the tenant.
     *
     * @param list<string> $newRoleCodes role codes the subject will hold
     *                                   after the edit (resolved by the
     *                                   controller from the requested
     *                                   role IDs)
     *
     * @throws LastAdminProtectionException when the new role set drops
     *                                      every admin grant AND no other
     *                                      active admin exists in the tenant
     */
    public function ensureRoleChangeKeepsAdmin(User $subject, array $newRoleCodes): void
    {
        if (!$this->isAdminInTenant($subject)) {
            return;
        }

        $stillAdmin = false;
        foreach ($newRoleCodes as $code) {
            if (\in_array($code, self::ADMIN_ROLE_CODES, true)) {
                $stillAdmin = true;
                break;
            }
        }
        if ($stillAdmin) {
            return;
        }

        $adminsLeft = $this->countOtherAdminsInTenant($subject);
        if ($adminsLeft <= 0) {
            throw LastAdminProtectionException::removingLastAdminRole($subject);
        }
    }

    private function isAdminInTenant(User $subject): bool
    {
        foreach ($subject->getAssignedRoles() as $role) {
            if (\in_array($role->getCode(), self::ADMIN_ROLE_CODES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * COUNT(DISTINCT u.id) of *active* users in the same tenant whose
     * role set intersects {tenant_owner, admin}, minus the subject. If
     * the subject is currently disabled it still counts as the "last
     * admin" precaution — re-enabling them after we drop the role
     * would leave the tenant headless.
     */
    private function countOtherAdminsInTenant(User $subject): int
    {
        // ADR-0034 (#2832) — reads the assignment table, the single record of
        // who holds what. While this still counted the Sprint-0 junction, a
        // tenant whose only remaining owner arrived through an invitation
        // (which never wrote there) would have counted as headless.
        $sql = <<<'SQL'
            SELECT COUNT(DISTINCT u.id)
            FROM users u
            INNER JOIN user_role_assignments ur ON ur.user_id = u.id
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE u.tenant_id = :tenant_id
              AND u.id != :subject_id
              AND u.status = 'active'
              AND r.code IN ('tenant_owner', 'admin')
              AND (r.tenant_id = :tenant_id OR r.tenant_id IS NULL)
            SQL;

        $count = $this->connection->fetchOne($sql, [
            'tenant_id' => $subject->getTenant()->getId()->toRfc4122(),
            'subject_id' => $subject->getId()->toRfc4122(),
        ]);

        // DBAL returns scalar | false. Postgres COUNT() never returns false
        // for a valid query, but PHPStan reads the union — narrow defensively.
        return \is_numeric($count) ? (int) $count : 0;
    }
}
