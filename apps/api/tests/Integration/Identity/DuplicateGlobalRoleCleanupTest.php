<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Infrastructure\Doctrine\DuplicateGlobalRoleCleanup;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * #2837 — the cleanup that removes global copies of tenant-facing roles,
 * exercised on the statement that actually ships
 * ({@see DuplicateGlobalRoleCleanup::SQL}).
 *
 * Two seeders used to emit overlapping codes, so a tenant ended up with a
 * global and a per-tenant `catalog_manager` — identical in the panel and,
 * for `viewer` and `integration_manager`, carrying different permission
 * sets underneath.
 *
 * The cases below are the three shapes that exist in the wild, and the
 * middle one is the reason this is a guarded DELETE rather than a plain
 * one: a global role somebody actually holds must survive, because
 * removing it would revoke access as a side effect of a migration.
 */
final class DuplicateGlobalRoleCleanupTest extends KernelTestCase
{
    #[Test]
    public function removesUnassignedDuplicatesAndSparesEverythingElse(): void
    {
        self::bootKernel();
        $connection = $this->connection();

        $suffix = bin2hex(random_bytes(4));
        $tenantId = Uuid::v7()->toRfc4122();

        $duplicateCode = 'dup-role-'.$suffix;       // global + tenant, unassigned → goes
        $heldCode = 'held-role-'.$suffix;           // global + tenant, but held → stays
        $globalOnlyCode = 'platform-role-'.$suffix; // no tenant twin → stays

        $globalDuplicate = Uuid::v7()->toRfc4122();
        $tenantDuplicate = Uuid::v7()->toRfc4122();
        $globalHeld = Uuid::v7()->toRfc4122();
        $tenantHeld = Uuid::v7()->toRfc4122();
        $globalOnly = Uuid::v7()->toRfc4122();
        $userId = Uuid::v7()->toRfc4122();

        try {
            $this->seedTenant($connection, $tenantId, 'dedupe-'.$suffix);

            $this->seedRole($connection, $globalDuplicate, null, $duplicateCode);
            $this->seedRole($connection, $tenantDuplicate, $tenantId, $duplicateCode);

            $this->seedRole($connection, $globalHeld, null, $heldCode);
            $this->seedRole($connection, $tenantHeld, $tenantId, $heldCode);

            $this->seedRole($connection, $globalOnly, null, $globalOnlyCode);

            // Somebody holds the global copy of `heldCode`.
            $this->seedUser($connection, $userId, $tenantId, 'dedupe-'.$suffix.'@demo.localhost');
            $this->seedAssignment($connection, $userId, $globalHeld);

            $connection->executeStatement(DuplicateGlobalRoleCleanup::SQL);

            self::assertFalse($this->roleExists($connection, $globalDuplicate), 'Unassigned global duplicate must be removed.');
            self::assertTrue($this->roleExists($connection, $tenantDuplicate), 'The tenant copy is the one that stays.');
            self::assertTrue(
                $this->roleExists($connection, $globalHeld),
                'A global role somebody holds must survive — deleting it would revoke access.',
            );
            self::assertTrue(
                $this->roleExists($connection, $globalOnly),
                'A global role without a tenant twin is not a duplicate (super_admin, platform_operator).',
            );

            // Idempotent.
            $connection->executeStatement(DuplicateGlobalRoleCleanup::SQL);
            self::assertTrue($this->roleExists($connection, $tenantDuplicate));
            self::assertTrue($this->roleExists($connection, $globalHeld));
        } finally {
            $connection->executeStatement('DELETE FROM user_role_assignments WHERE user_id = :id', ['id' => $userId]);
            $connection->executeStatement('DELETE FROM users WHERE id = :id', ['id' => $userId]);
            foreach ([$globalDuplicate, $tenantDuplicate, $globalHeld, $tenantHeld, $globalOnly] as $roleId) {
                $connection->executeStatement('DELETE FROM roles WHERE id = :id', ['id' => $roleId]);
            }
            $connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $tenantId]);
        }
    }

    private function roleExists(Connection $connection, string $id): bool
    {
        return (bool) $connection->fetchOne('SELECT COUNT(*) FROM roles WHERE id = :id', ['id' => $id]);
    }

    private function seedTenant(Connection $connection, string $id, string $code): void
    {
        $connection->executeStatement(
            'INSERT INTO tenants (id, code, name, created_at) VALUES (:id, :code, :name, NOW())',
            ['id' => $id, 'code' => $code, 'name' => $code],
        );
    }

    private function seedRole(Connection $connection, string $id, ?string $tenantId, string $code): void
    {
        $connection->executeStatement(
            'INSERT INTO roles (id, tenant_id, code, name, created_at) VALUES (:id, :tenant, :code, :name, NOW())',
            ['id' => $id, 'tenant' => $tenantId, 'code' => $code, 'name' => $code],
        );
    }

    private function seedUser(Connection $connection, string $id, string $tenantId, string $email): void
    {
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO users (id, tenant_id, email, password, roles, status, totp_backup_codes, created_at, password_change_required)
                VALUES (:id, :tenant, :email, '', '["ROLE_USER"]', 'active', '[]', NOW(), false)
                SQL,
            ['id' => $id, 'tenant' => $tenantId, 'email' => $email],
        );
    }

    private function seedAssignment(Connection $connection, string $userId, string $roleId): void
    {
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO user_role_assignments (id, user_id, role_id, locale_scope, channel_scope, attribute_group_scope, assigned_at)
                VALUES (:id, :user, :role, CAST('[]' AS json), CAST('[]' AS json), CAST('[]' AS json), NOW())
                SQL,
            ['id' => Uuid::v7()->toRfc4122(), 'user' => $userId, 'role' => $roleId],
        );
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
