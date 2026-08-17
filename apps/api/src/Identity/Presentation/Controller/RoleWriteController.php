<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Application\PermissionResolverInterface;
use App\Identity\Application\RoleDetailResponseBuilder;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Entity\Permission;
use App\Identity\Domain\Entity\Role;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\PrdRoleTemplates;
use App\Identity\Domain\Rbac\RbacMatrix;
use App\Identity\Domain\Repository\PermissionRepositoryInterface;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * RBAC-P5-006 (#696) — write operations on roles:
 *
 *   - POST   /api/roles       — create custom role on caller's tenant
 *   - PATCH  /api/roles/{id}  — update permissions (system + custom);
 *                               name / code editable for custom only
 *   - DELETE /api/roles/{id}  — delete custom role; 409 if any user
 *                               still holds it (operator must reassign
 *                               first); built-in roles return 403
 *
 * The permission set is the canonical source of truth for the matrix
 * grid in the FE — codes are resolved against the seeded
 * {@see Permission} catalogue, so unknown codes surface as 400 and the
 * matrix never offers cells that the engine cannot enforce.
 */
final readonly class RoleWriteController
{
    private const int MAX_NAME_LENGTH = 80;
    private const int MAX_CODE_LENGTH = 64;

    public function __construct(
        private Security $security,
        private RoleRepositoryInterface $roles,
        private PermissionRepositoryInterface $permissions,
        private UserRepositoryInterface $users,
        private RoleDetailResponseBuilder $builder,
        private PermissionResolverInterface $permissionResolver,
    ) {
    }

    #[Route(path: '/api/roles', methods: ['POST'], name: 'api_roles_create')]
    #[RequiresPermission(module: 'user', action: 'admin', anyOf: ['user.admin', 'settings.roles.manage'])]
    public function create(Request $request): JsonResponse
    {
        $caller = $this->security->getUser();
        if (!$caller instanceof User) {
            return $this->problem(Response::HTTP_UNAUTHORIZED, 'Unauthorized', 'No authenticated user.');
        }

        $payload = $this->decode($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $name = $this->extractName($payload);
        if ($name instanceof JsonResponse) {
            return $name;
        }

        $code = $this->extractCode($payload, $name);
        if ($code instanceof JsonResponse) {
            return $code;
        }

        $existing = $this->roles->findByCode($code, $caller->getTenant());
        if (null !== $existing) {
            return $this->problem(
                Response::HTTP_CONFLICT,
                'Duplicate code',
                \sprintf('A role with code `%s` already exists on this tenant.', $code),
                ['code' => 'duplicate_code'],
            );
        }

        $permissions = $this->resolvePermissions($payload);
        if ($permissions instanceof JsonResponse) {
            return $permissions;
        }

        $role = new Role(code: $code, name: $name, tenant: $caller->getTenant());
        foreach ($permissions as $permission) {
            $role->grantPermission($permission);
        }
        if (\array_key_exists('auto_grant_new_object_types', $payload)) {
            $role->setAutoGrantNewObjectTypes((bool) $payload['auto_grant_new_object_types']);
        }
        if (\array_key_exists('description', $payload)) {
            $descriptionRaw = $payload['description'];
            if (null !== $descriptionRaw && !\is_string($descriptionRaw)) {
                return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', '`description` must be a string or null.');
            }
            $role->setDescription($descriptionRaw);
        }
        $this->roles->save($role);

        return new JsonResponse($this->builder->buildOne($role), Response::HTTP_CREATED);
    }

    #[Route(path: '/api/roles/{id}', methods: ['PATCH'], name: 'api_roles_update', requirements: ['id' => '[0-9a-f-]{36}'])]
    #[RequiresPermission(module: 'user', action: 'admin', anyOf: ['user.admin', 'settings.roles.manage'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $caller = $this->security->getUser();
        if (!$caller instanceof User) {
            return $this->problem(Response::HTTP_UNAUTHORIZED, 'Unauthorized', 'No authenticated user.');
        }

        $role = $this->loadAccessibleRole($caller, $id);
        if ($role instanceof JsonResponse) {
            return $role;
        }

        $payload = $this->decode($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        // #2837 — PRD template roles are built-in even though they live on
        // a tenant; renaming them would desynchronise them from the seeder.
        $isCustom = !$role->isGlobal() && !PrdRoleTemplates::isTemplateCode($role->getCode());

        // #2881 — reject a RENAME, not a payload that merely mentions the
        // name. The role editor PATCHes the whole role, so a built-in role
        // always carried its own unchanged name and the guard refused every
        // edit — including the permission-only ones it explicitly tells the
        // operator to make ("Edit only `permission_codes`"). Granting a
        // permission to a built-in role was impossible through the UI.
        if (\array_key_exists('name', $payload)) {
            $name = $this->extractName($payload);
            if ($name instanceof JsonResponse) {
                return $name;
            }
            if ($name !== $role->getName()) {
                if (!$isCustom) {
                    return $this->problem(
                        Response::HTTP_BAD_REQUEST,
                        'Bad Request',
                        'Built-in roles cannot be renamed. Edit only `permission_codes`.',
                    );
                }
                $role->rename($name);
            }
        }

        // Same shape for the code: echoing back the current one is not an
        // attempt to change it.
        if (\array_key_exists('code', $payload) && !$isCustom && $payload['code'] !== $role->getCode()) {
            return $this->problem(
                Response::HTTP_BAD_REQUEST,
                'Bad Request',
                'Built-in roles use an immutable code.',
            );
        }
        // Custom-role code is kept stable too — renaming the wire
        // identifier would break any external integration that resolves
        // a user's roles by their code, so the PATCH path intentionally
        // does NOT accept `code` even for custom. The list view shows
        // the code so an operator can audit it; if a rename is needed,
        // delete + recreate. The check above only flags the request
        // shape so the FE can surface a useful error.

        if (\array_key_exists('permission_codes', $payload)) {
            $permissions = $this->resolvePermissions($payload);
            if ($permissions instanceof JsonResponse) {
                return $permissions;
            }
            $this->replacePermissions($role, $permissions);
        }

        if (\array_key_exists('auto_grant_new_object_types', $payload)) {
            $role->setAutoGrantNewObjectTypes((bool) $payload['auto_grant_new_object_types']);
        }

        if (\array_key_exists('description', $payload)) {
            $descriptionRaw = $payload['description'];
            if (null !== $descriptionRaw && !\is_string($descriptionRaw)) {
                return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', '`description` must be a string or null.');
            }
            $role->setDescription($descriptionRaw);
        }

        $this->roles->save($role);

        // #2881 — every holder of this role is carrying a cached permission
        // set that no longer matches the database. Without this the grant
        // lands in `role_permissions` and changes nothing the user can see
        // until the entry expires, which reads as "the save did not work" —
        // and the editor's own footer promises the opposite.
        $this->permissionResolver->invalidateRole($role->getId()->toRfc4122());

        return new JsonResponse($this->builder->buildOne($role));
    }

    #[Route(path: '/api/roles/{id}', methods: ['DELETE'], name: 'api_roles_delete', requirements: ['id' => '[0-9a-f-]{36}'])]
    #[RequiresPermission(module: 'user', action: 'admin', anyOf: ['user.admin', 'settings.roles.manage'])]
    public function delete(string $id): JsonResponse
    {
        $caller = $this->security->getUser();
        if (!$caller instanceof User) {
            return $this->problem(Response::HTTP_UNAUTHORIZED, 'Unauthorized', 'No authenticated user.');
        }

        $role = $this->loadAccessibleRole($caller, $id);
        if ($role instanceof JsonResponse) {
            return $role;
        }

        // #2837 — protection covers the PRD templates too. Guarding on
        // globality alone left `tenant_owner`, `catalog_manager` and the
        // rest deletable from the panel the moment nobody held them.
        if ($role->isGlobal() || PrdRoleTemplates::isTemplateCode($role->getCode())) {
            return $this->problem(
                Response::HTTP_FORBIDDEN,
                'Forbidden',
                'Built-in system roles cannot be deleted.',
            );
        }

        $userCount = $this->users->countAssignedToRole($role->getId());
        if ($userCount > 0) {
            return $this->problem(
                Response::HTTP_CONFLICT,
                'Role in use',
                \sprintf('Cannot delete role: %d user(s) still hold this role. Reassign them first.', $userCount),
                ['code' => 'role_in_use', 'user_count' => $userCount],
            );
        }

        $this->roles->remove($role);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function loadAccessibleRole(User $caller, string $id): Role|JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (InvalidArgumentException) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'Not Found', 'Role not found.');
        }

        $role = $this->roles->findById($uuid);
        if (null === $role) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'Not Found', 'Role not found.');
        }

        // #2881 — a GLOBAL role is shared by every tenant, and this loader
        // used to wave one through on the strength of being global: any
        // tenant's admin could PATCH `super_admin` and change what it grants
        // across the whole installation. Editing a cross-tenant role belongs
        // to the platform operator, so everyone else gets the same "not
        // found" a foreign tenant's role gets — the answer must not reveal
        // that the role exists.
        if ($role->isGlobal()) {
            if (!$this->permissionResolver->resolve($caller)->has(RbacMatrix::PERMISSION_PLATFORM_TENANTS_MANAGE)) {
                return $this->problem(Response::HTTP_NOT_FOUND, 'Not Found', 'Role not found.');
            }

            return $role;
        }

        $roleTenant = $role->getTenant();
        if (null === $roleTenant || !$caller->getTenant()->getId()->equals($roleTenant->getId())) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'Not Found', 'Role not found.');
        }

        return $role;
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function decode(Request $request): array|JsonResponse
    {
        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', 'Request body must be JSON.');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractName(array $payload): string|JsonResponse
    {
        $name = $payload['name'] ?? null;
        if (!\is_string($name) || '' === trim($name)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', '`name` is required and must be a non-empty string.');
        }
        $name = trim($name);
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            return $this->problem(
                Response::HTTP_BAD_REQUEST,
                'Bad Request',
                \sprintf('`name` must be %d characters or fewer.', self::MAX_NAME_LENGTH),
            );
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractCode(array $payload, string $name): string|JsonResponse
    {
        $code = $payload['code'] ?? null;
        if (null === $code || (\is_string($code) && '' === trim($code))) {
            $code = self::slugify($name);
        }
        if (!\is_string($code) || '' === $code) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', '`code` must be a non-empty string.');
        }
        $code = trim($code);
        if (mb_strlen($code) > self::MAX_CODE_LENGTH) {
            return $this->problem(
                Response::HTTP_BAD_REQUEST,
                'Bad Request',
                \sprintf('`code` must be %d characters or fewer.', self::MAX_CODE_LENGTH),
            );
        }
        if (1 !== preg_match('/^[a-z0-9_]+$/', $code)) {
            return $this->problem(
                Response::HTTP_BAD_REQUEST,
                'Bad Request',
                '`code` must contain only lowercase letters, digits and underscores.',
            );
        }

        return $code;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<Permission>|JsonResponse
     */
    private function resolvePermissions(array $payload): array|JsonResponse
    {
        $raw = $payload['permission_codes'] ?? [];
        if (!\is_array($raw)) {
            return $this->problem(
                Response::HTTP_BAD_REQUEST,
                'Bad Request',
                '`permission_codes` must be an array of strings.',
            );
        }

        // Deduplicate input and resolve each code against the catalogue.
        // Unknown codes 400 — the matrix only ever offers seeded codes,
        // so a 400 here indicates a bug or a stale FE bundle rather
        // than legitimate user input.
        $seen = [];
        $resolved = [];
        foreach ($raw as $candidate) {
            if (!\is_string($candidate) || '' === trim($candidate)) {
                return $this->problem(
                    Response::HTTP_BAD_REQUEST,
                    'Bad Request',
                    'Each permission code must be a non-empty string.',
                );
            }
            $candidate = trim($candidate);
            if (isset($seen[$candidate])) {
                continue;
            }
            $seen[$candidate] = true;
            $permission = $this->permissions->findByCode($candidate);
            if (null === $permission) {
                return $this->problem(
                    Response::HTTP_BAD_REQUEST,
                    'Bad Request',
                    \sprintf('Unknown permission code `%s`.', $candidate),
                );
            }
            $resolved[] = $permission;
        }

        return $resolved;
    }

    /**
     * @param list<Permission> $next
     */
    private function replacePermissions(Role $role, array $next): void
    {
        $current = [];
        foreach ($role->getPermissions() as $permission) {
            $current[$permission->getCode()] = $permission;
        }
        $nextByCode = [];
        foreach ($next as $permission) {
            $nextByCode[$permission->getCode()] = $permission;
        }

        foreach ($current as $code => $permission) {
            if (!isset($nextByCode[$code])) {
                $role->revokePermission($permission);
            }
        }
        foreach ($nextByCode as $code => $permission) {
            if (!isset($current[$code])) {
                $role->grantPermission($permission);
            }
        }
    }

    private static function slugify(string $name): string
    {
        $slug = mb_strtolower($name, 'UTF-8');
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        if (null === $slug) {
            return '';
        }

        return trim($slug, '_');
    }

    /**
     * @param array<string, mixed> $extras
     */
    private function problem(int $status, string $title, string $detail, array $extras = []): JsonResponse
    {
        return new JsonResponse(
            array_merge(
                [
                    'type' => 'about:blank',
                    'title' => $title,
                    'status' => $status,
                    'detail' => $detail,
                ],
                $extras,
            ),
            $status,
            ['Content-Type' => 'application/problem+json; charset=utf-8'],
        );
    }
}
