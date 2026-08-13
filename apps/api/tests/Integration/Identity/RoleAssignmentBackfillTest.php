<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\PermissionResolverInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Infrastructure\Doctrine\RoleAssignmentBackfill;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-0034 (#2832) — the expand-step backfill, exercised on the statement
 * that actually ships ({@see RoleAssignmentBackfill::SQL}).
 *
 * Why this test carries weight beyond the usual migration smoke: on the
 * production instance the owner account held its roles ONLY in the legacy
 * `user_roles` junction, because it was created by the tenant bootstrap
 * rather than an invitation. Releasing code that reads only
 * `user_role_assignments` without this copy running first would have left
 * the operator without permissions on their own instance.
 *
 * The legacy table no longer exists in the ORM metadata the test schema is
 * built from, so the test creates it, seeds the three shapes that exist in
 * the wild, runs the backfill, and drops it again.
 */
final class RoleAssignmentBackfillTest extends KernelTestCase
{
    #[Test]
    public function legacyOnlyGrantSurvivesAndResolvesToPermissions(): void
    {
        self::bootKernel();
        $connection = $this->connection();

        $suffix = bin2hex(random_bytes(4));
        $tenantId = Uuid::v7()->toRfc4122();
        $roleId = Uuid::v7()->toRfc4122();
        $permissionId = Uuid::v7()->toRfc4122();
        $legacyOnlyUser = Uuid::v7()->toRfc4122();
        $bothTablesUser = Uuid::v7()->toRfc4122();
        $assignmentOnlyUser = Uuid::v7()->toRfc4122();
        $code = 'products.view.backfill.'.$suffix;

        $this->createLegacyTable($connection);

        try {
            $this->seedTenant($connection, $tenantId, 'adr34-backfill-'.$suffix);
            $this->seedRole($connection, $roleId, $tenantId, 'backfill-role-'.$suffix);
            $this->seedPermission($connection, $permissionId, $code);
            $this->linkRolePermission($connection, $roleId, $permissionId);

            // Shape 1 — the bootstrap owner: legacy row only.
            $this->seedUser($connection, $legacyOnlyUser, $tenantId, 'legacy-'.$suffix.'@demo.localhost');
            $this->seedLegacyRow($connection, $legacyOnlyUser, $roleId);

            // Shape 2 — created by UserCreateService: written to both tables.
            $this->seedUser($connection, $bothTablesUser, $tenantId, 'both-'.$suffix.'@demo.localhost');
            $this->seedLegacyRow($connection, $bothTablesUser, $roleId);
            $this->seedAssignment($connection, $bothTablesUser, $roleId);

            // Shape 3 — accepted an invitation: assignment only.
            $this->seedUser($connection, $assignmentOnlyUser, $tenantId, 'invited-'.$suffix.'@demo.localhost');
            $this->seedAssignment($connection, $assignmentOnlyUser, $roleId);

            $connection->executeStatement(RoleAssignmentBackfill::SQL);

            // The account that would otherwise have lost everything now holds
            // its grant where the application reads it.
            self::assertSame(1, $this->assignmentCount($connection, $legacyOnlyUser));
            self::assertContains(
                $code,
                $this->resolver()->resolve($this->loadUser($legacyOnlyUser))->getCodes(),
                'A legacy-only grant must resolve to its permissions after the backfill.',
            );

            // No duplicates for the pair that already existed on both sides…
            self::assertSame(1, $this->assignmentCount($connection, $bothTablesUser));
            // …and the invitation-created one is untouched.
            self::assertSame(1, $this->assignmentCount($connection, $assignmentOnlyUser));

            // Idempotent: running it again changes nothing.
            $connection->executeStatement(RoleAssignmentBackfill::SQL);
            self::assertSame(1, $this->assignmentCount($connection, $legacyOnlyUser));
            self::assertSame(1, $this->assignmentCount($connection, $bothTablesUser));
        } finally {
            foreach ([$legacyOnlyUser, $bothTablesUser, $assignmentOnlyUser] as $userId) {
                $connection->executeStatement('DELETE FROM user_role_assignments WHERE user_id = :id', ['id' => $userId]);
                $connection->executeStatement('DELETE FROM users WHERE id = :id', ['id' => $userId]);
            }
            $connection->executeStatement('DELETE FROM role_permissions WHERE role_id = :id', ['id' => $roleId]);
            $connection->executeStatement('DELETE FROM permissions WHERE id = :id', ['id' => $permissionId]);
            $connection->executeStatement('DELETE FROM roles WHERE id = :id', ['id' => $roleId]);
            $connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $tenantId]);
            $connection->executeStatement('DROP TABLE IF EXISTS user_roles');
        }
    }

    private function createLegacyTable(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS user_roles (
                user_id UUID NOT NULL,
                role_id UUID NOT NULL,
                PRIMARY KEY (user_id, role_id)
            )
            SQL);
    }

    private function assignmentCount(Connection $connection, string $userId): int
    {
        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM user_role_assignments WHERE user_id = :id',
            ['id' => $userId],
        );

        return \is_numeric($count) ? (int) $count : 0;
    }

    private function seedTenant(Connection $connection, string $id, string $code): void
    {
        $connection->executeStatement(
            'INSERT INTO tenants (id, code, name, created_at) VALUES (:id, :code, :name, NOW())',
            ['id' => $id, 'code' => $code, 'name' => $code],
        );
    }

    private function seedRole(Connection $connection, string $id, string $tenantId, string $code): void
    {
        $connection->executeStatement(
            'INSERT INTO roles (id, tenant_id, code, name, created_at) VALUES (:id, :tenant, :code, :name, NOW())',
            ['id' => $id, 'tenant' => $tenantId, 'code' => $code, 'name' => $code],
        );
    }

    private function seedPermission(Connection $connection, string $id, string $code): void
    {
        $connection->executeStatement(
            'INSERT INTO permissions (id, code, resource, action, created_at) VALUES (:id, :code, :resource, :action, NOW())',
            ['id' => $id, 'code' => $code, 'resource' => $code, 'action' => 'view'],
        );
    }

    private function linkRolePermission(Connection $connection, string $roleId, string $permissionId): void
    {
        $connection->executeStatement(
            'INSERT INTO role_permissions (role_id, permission_id) VALUES (:role, :permission)',
            ['role' => $roleId, 'permission' => $permissionId],
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

    private function seedLegacyRow(Connection $connection, string $userId, string $roleId): void
    {
        $connection->executeStatement(
            'INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)',
            ['user' => $userId, 'role' => $roleId],
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

    private function loadUser(string $userId): User
    {
        $user = $this->em()->getRepository(User::class)->find(Uuid::fromString($userId));
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function resolver(): PermissionResolverInterface
    {
        $resolver = self::getContainer()->get(PermissionResolverInterface::class);
        self::assertInstanceOf(PermissionResolverInterface::class, $resolver);

        return $resolver;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
