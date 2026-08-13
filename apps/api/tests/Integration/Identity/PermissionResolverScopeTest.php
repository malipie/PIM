<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\PermissionResolverInterface;
use App\Identity\Domain\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-0034 (#2832) — scope resolution over the consolidated assignment table.
 *
 * History this replaces: grants used to live in two tables, and the Sprint-0
 * `user_roles` M2M had no scope columns, so it projected a literal `'[]'`
 * into the resolver's union. {@see \App\Identity\Application\PermissionResolver::mergeScope}
 * reads an empty array as "no restriction", so a role granted for one locale
 * was silently widened to all of them by its own duplicate row — AUD-029
 * (#1611), patched back then by excluding duplicates from the union. With one
 * table the failure mode cannot recur, and what remains worth pinning is the
 * behaviour itself: a scoped assignment stays scoped, an unscoped one stays
 * open.
 *
 * Uses the real container Connection + PermissionResolver against the test DB
 * (schema built from ORM metadata), seeds raw rows, cleans up in a finally.
 */
final class PermissionResolverScopeTest extends KernelTestCase
{
    #[Test]
    public function scopedAssignmentStaysRestrictedToItsLocale(): void
    {
        self::bootKernel();
        $connection = $this->connection();

        $suffix = bin2hex(random_bytes(4));
        $tenantId = Uuid::v7()->toRfc4122();
        $roleId = Uuid::v7()->toRfc4122();
        $permissionId = Uuid::v7()->toRfc4122();
        $userId = Uuid::v7()->toRfc4122();
        $assignmentId = Uuid::v7()->toRfc4122();
        $code = 'products.view.scoped.'.$suffix;

        try {
            $this->seedTenant($connection, $tenantId, 'adr34-a-'.$suffix);
            $this->seedRole($connection, $roleId, $tenantId, 'scoped-role-'.$suffix);
            $this->seedPermission($connection, $permissionId, $code);
            $this->linkRolePermission($connection, $roleId, $permissionId);
            $this->seedUser($connection, $userId, $tenantId, 'adr34-'.$suffix.'@demo.localhost');

            // Role restricted to the `pl` locale.
            $this->seedAssignment($connection, $assignmentId, $userId, $roleId, '["pl"]');

            $resolved = $this->resolver()->resolve($this->loadUser($userId));

            self::assertContains($code, $resolved->getCodes());
            self::assertSame(['pl'], $resolved->getLocaleScope());
            self::assertTrue($resolved->appliesToLocale('pl'));
            self::assertFalse(
                $resolved->appliesToLocale('en'),
                'A non-pl locale must stay out of scope; a true here means something '
                .'re-opened the full locale set.',
            );
        } finally {
            $this->cleanup($connection, $userId, $roleId, $permissionId, $tenantId);
        }
    }

    #[Test]
    public function unscopedAssignmentAppliesEverywhere(): void
    {
        self::bootKernel();
        $connection = $this->connection();

        $suffix = bin2hex(random_bytes(4));
        $tenantId = Uuid::v7()->toRfc4122();
        $roleId = Uuid::v7()->toRfc4122();
        $permissionId = Uuid::v7()->toRfc4122();
        $userId = Uuid::v7()->toRfc4122();
        $assignmentId = Uuid::v7()->toRfc4122();
        $code = 'platform.tenants.manage.open.'.$suffix;

        try {
            $this->seedTenant($connection, $tenantId, 'adr34-b-'.$suffix);
            $this->seedRole($connection, $roleId, $tenantId, 'open-role-'.$suffix);
            $this->seedPermission($connection, $permissionId, $code);
            $this->linkRolePermission($connection, $roleId, $permissionId);
            $this->seedUser($connection, $userId, $tenantId, 'adr34b-'.$suffix.'@demo.localhost');

            // Empty scope — how tenant_owner / super_admin are granted.
            $this->seedAssignment($connection, $assignmentId, $userId, $roleId, '[]');

            $resolved = $this->resolver()->resolve($this->loadUser($userId));

            self::assertContains($code, $resolved->getCodes());
            self::assertSame([], $resolved->getLocaleScope());
            self::assertTrue($resolved->appliesToLocale('en'), 'An unrestricted role applies to any locale.');
            self::assertTrue($resolved->appliesToChannel('shopify'), 'An unrestricted role applies to any channel.');
        } finally {
            $this->cleanup($connection, $userId, $roleId, $permissionId, $tenantId);
        }
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
        // resource/action have their own UNIQUE constraint; derive unique values
        // from the (already unique) code so parallel runs never collide.
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

    private function seedAssignment(Connection $connection, string $id, string $userId, string $roleId, string $localeScope): void
    {
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO user_role_assignments (id, user_id, role_id, locale_scope, channel_scope, attribute_group_scope, assigned_at)
                VALUES (:id, :user, :role, CAST(:locale AS json), CAST('[]' AS json), CAST('[]' AS json), NOW())
                SQL,
            ['id' => $id, 'user' => $userId, 'role' => $roleId, 'locale' => $localeScope],
        );
    }

    private function cleanup(
        Connection $connection,
        string $userId,
        string $roleId,
        string $permissionId,
        string $tenantId,
    ): void {
        // FK order: junctions first, then owned rows, then the tenant.
        $connection->executeStatement('DELETE FROM user_role_assignments WHERE user_id = :id', ['id' => $userId]);
        $connection->executeStatement('DELETE FROM role_permissions WHERE role_id = :id', ['id' => $roleId]);
        $connection->executeStatement('DELETE FROM users WHERE id = :id', ['id' => $userId]);
        $connection->executeStatement('DELETE FROM permissions WHERE id = :id', ['id' => $permissionId]);
        $connection->executeStatement('DELETE FROM roles WHERE id = :id', ['id' => $roleId]);
        $connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $tenantId]);
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
