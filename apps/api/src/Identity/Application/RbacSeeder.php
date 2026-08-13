<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Entity\Permission;
use App\Identity\Domain\Entity\Role;
use App\Identity\Domain\Rbac\PermissionDefinition;
use App\Identity\Domain\Rbac\RbacMatrix;
use App\Identity\Domain\Rbac\RoleDefinition;
use App\Identity\Domain\Repository\PermissionRepositoryInterface;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

/**
 * Idempotent seeder for the built-in PLATFORM roles + the legacy
 * permission catalogue.
 *
 * #2837 — "global roles" now means exactly `super_admin` and
 * `platform_operator`. Tenant-facing roles come from
 * {@see SeedTenantPrdRolesService}; while both
 * seeders emitted `catalog_manager` / `integration_manager` / `viewer`,
 * every tenant ended up with two rows per code — same name in the panel,
 * different grants underneath.
 *
 * Re-running the seeder is supported: existing rows are matched by code or
 * (resource, action) and updated rather than duplicated. The unique indexes
 * from #24 backstop the contract — a duplicate would fail the SQL constraint.
 *
 * Permissions are seeded first because RoleDefinition.permissionCodes
 * references them. An unknown permission code is treated as a programming
 * error in {@see RbacMatrix} (typo, missing entry) — callers see an exception
 * rather than a silently mis-permissioned role.
 */
final class RbacSeeder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PermissionRepositoryInterface $permissions,
        private readonly RoleRepositoryInterface $roles,
    ) {
    }

    public function seed(): RbacSeederReport
    {
        $this->permissionsCreated = 0;
        $this->rolesCreated = 0;
        $this->rolesUpdated = 0;

        $permissionsByCode = $this->seedPermissions();
        $this->seedRoles($permissionsByCode);

        $this->em->flush();

        return new RbacSeederReport(
            permissionsCreated: $this->permissionsCreated,
            rolesCreated: $this->rolesCreated,
            rolesUpdated: $this->rolesUpdated,
        );
    }

    private int $permissionsCreated = 0;
    private int $rolesCreated = 0;
    private int $rolesUpdated = 0;

    /**
     * @return array<string, Permission>
     */
    private function seedPermissions(): array
    {
        $byCode = [];

        foreach (RbacMatrix::permissions() as $definition) {
            $permission = $this->upsertPermission($definition);
            $byCode[$permission->getCode()] = $permission;
        }

        return $byCode;
    }

    private function upsertPermission(PermissionDefinition $definition): Permission
    {
        $existing = $this->permissions->findByResourceAction($definition->resource, $definition->action);

        if (null !== $existing) {
            return $existing;
        }

        $permission = new Permission(
            resource: $definition->resource,
            action: $definition->action,
        );
        $this->em->persist($permission);
        ++$this->permissionsCreated;

        return $permission;
    }

    /**
     * @param array<string, Permission> $permissionsByCode
     */
    private function seedRoles(array $permissionsByCode): void
    {
        foreach (RbacMatrix::roles() as $definition) {
            $role = $this->roles->findGlobalByCode($definition->code);
            $isNew = false;

            if (null === $role) {
                $role = new Role(code: $definition->code, name: $definition->name);
                $this->em->persist($role);
                ++$this->rolesCreated;
                $isNew = true;
            }

            $renamed = !$isNew && $role->getName() !== $definition->name;
            if ($renamed) {
                $role->rename($definition->name);
            }

            $membershipChanged = $this->syncPermissions($role, $definition, $permissionsByCode);

            if (!$isNew && ($renamed || $membershipChanged)) {
                ++$this->rolesUpdated;
            }
        }
    }

    /**
     * @param array<string, Permission> $permissionsByCode
     */
    private function syncPermissions(Role $role, RoleDefinition $definition, array $permissionsByCode): bool
    {
        $desired = [];
        foreach ($definition->permissionCodes as $code) {
            $permission = $permissionsByCode[$code]
                ?? throw new LogicException(\sprintf(
                    'RBAC matrix references unknown permission "%s" for role "%s". Update RbacMatrix::permissions() or fix the typo.',
                    $code,
                    $definition->code,
                ));
            $desired[$code] = $permission;
        }

        $current = [];
        foreach ($role->getPermissions() as $existing) {
            $current[$existing->getCode()] = $existing;
        }

        $changed = false;

        foreach ($desired as $code => $permission) {
            if (!isset($current[$code])) {
                $role->grantPermission($permission);
                $changed = true;
            }
        }

        foreach ($current as $code => $permission) {
            if (!isset($desired[$code])) {
                $role->revokePermission($permission);
                $changed = true;
            }
        }

        return $changed;
    }
}
