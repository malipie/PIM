<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Application\LastAdminGuard;
use App\Identity\Application\UserListResponseBuilder;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Entity\Role;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Exception\LastAdminProtectionException;
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
 * RBAC-P5-003 (#693) — `PATCH /api/users/{id}` updates a user's role
 * assignments. Profile fields (name, avatar) are deferred per ticket
 * body — the User entity has no first_name/last_name columns yet, so
 * the projected `display_name` keeps being derived from email until a
 * follow-up adds those columns.
 *
 * Body shape:
 *   { "role_ids": ["uuid", "uuid", ...] }
 *
 * Semantics:
 *   - `role_ids` REPLACES the user's assigned-role set (full overwrite,
 *     not delta). Empty array is valid — it clears all role assignments.
 *   - Each id must resolve to either a global role (tenant_id IS NULL)
 *     or a custom role belonging to the caller's tenant. Cross-tenant
 *     ids return 400 ("Unknown role id").
 *   - Self-edit of own roles is forbidden (409 `code: "self_edit"`) so a
 *     tenant_owner cannot accidentally lock themselves out.
 *   - Last-admin protection: refuses an edit that would strip the
 *     Administrator grant from the only active admin on the tenant
 *     (409 `code: "last_admin"`).
 *
 * Permission gate: `user.admin` legacy RbacMatrix until Phase 6 retrofit
 * (#720+) migrates onto PRD §3.2 `settings.users.manage`.
 */
final readonly class UserUpdateController
{
    public function __construct(
        private Security $security,
        private UserRepositoryInterface $users,
        private RoleRepositoryInterface $roles,
        private UserListResponseBuilder $builder,
        private LastAdminGuard $lastAdminGuard,
    ) {
    }

    #[Route(path: '/api/users/{id}', methods: ['PATCH'], name: 'api_users_update', requirements: ['id' => '[0-9a-f-]{36}'])]
    #[RequiresPermission(module: 'user', action: 'admin', anyOf: ['user.admin', 'settings.users.manage'])]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $caller = $this->security->getUser();
        if (!$caller instanceof User) {
            return $this->problem(Response::HTTP_UNAUTHORIZED, 'Unauthorized', 'No authenticated user.');
        }

        $target = $this->loadTargetInSameTenant($caller, $id);
        if ($target instanceof JsonResponse) {
            return $target;
        }

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', 'Request body must be JSON.');
        }

        if (!\array_key_exists('role_ids', $payload)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', '`role_ids` is required.');
        }

        $rawIds = $payload['role_ids'];
        if (!\is_array($rawIds)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', '`role_ids` must be an array of UUIDs.');
        }

        // Caller cannot edit their own role set. Self-deactivation is blocked
        // in UserDeactivationController for the same reason — both write paths
        // close the "lock-yourself-out" hole.
        if ($caller->getId()->equals($target->getId())) {
            return $this->problem(
                Response::HTTP_CONFLICT,
                'Self-edit forbidden',
                'You cannot change your own roles.',
                ['code' => 'self_edit'],
            );
        }

        $resolvedRoles = [];
        foreach ($rawIds as $candidate) {
            if (!\is_string($candidate)) {
                return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', 'Each role id must be a UUID string.');
            }
            try {
                $uuid = Uuid::fromString($candidate);
            } catch (InvalidArgumentException) {
                return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', \sprintf('Invalid role id `%s`.', $candidate));
            }
            $role = $this->roles->findById($uuid);
            if (null === $role || !$this->roleIsAccessibleByCaller($role, $caller)) {
                return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', \sprintf('Unknown role id `%s`.', $candidate));
            }
            $resolvedRoles[$role->getId()->toRfc4122()] = $role;
        }

        $newRoleCodes = array_values(array_map(static fn (Role $r): string => $r->getCode(), $resolvedRoles));

        try {
            $this->lastAdminGuard->ensureRoleChangeKeepsAdmin($target, $newRoleCodes);
        } catch (LastAdminProtectionException $error) {
            return $this->problem(
                Response::HTTP_CONFLICT,
                'Last administrator protection',
                $error->getMessage(),
                ['code' => 'last_admin'],
            );
        }

        // Replace the assigned-role set through the entity so Doctrine's
        // unit-of-work emits the DELETE / INSERT on `user_role_assignments`
        // itself — writing that junction with raw SQL would skip the
        // listeners (ADR-0034 keeps addRole()/removeRole() the only path).
        $currentRoles = [];
        foreach ($target->getAssignedRoles() as $role) {
            $currentRoles[$role->getId()->toRfc4122()] = $role;
        }
        foreach ($currentRoles as $idStr => $role) {
            if (!isset($resolvedRoles[$idStr])) {
                $target->removeRole($role);
            }
        }
        foreach ($resolvedRoles as $idStr => $role) {
            if (!isset($currentRoles[$idStr])) {
                $target->addRole($role);
            }
        }

        $this->users->save($target);

        return new JsonResponse($this->builder->buildOne($target));
    }

    private function loadTargetInSameTenant(User $caller, string $id): User|JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (InvalidArgumentException) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'Not Found', 'User not found.');
        }

        $target = $this->users->findById($uuid);
        if (null === $target) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'Not Found', 'User not found.');
        }

        if (!$caller->getTenant()->getId()->equals($target->getTenant()->getId())) {
            // Defence in depth — TenantFilter scopes the repository query,
            // a hand-crafted cross-tenant UUID must never escalate here.
            return $this->problem(Response::HTTP_NOT_FOUND, 'Not Found', 'User not found.');
        }

        return $target;
    }

    private function roleIsAccessibleByCaller(Role $role, User $caller): bool
    {
        $roleTenant = $role->getTenant();
        if (null === $roleTenant) {
            // Global / system role — assignable by anyone with `user.admin`.
            return true;
        }

        return $roleTenant->getId()->equals($caller->getTenant()->getId());
    }

    /**
     * @param array<string, mixed> $extras
     */
    private function problem(int $status, string $title, string $detail, array $extras = []): JsonResponse
    {
        $body = array_merge(
            [
                'type' => 'about:blank',
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
            ],
            $extras,
        );

        return new JsonResponse(
            $body,
            $status,
            ['Content-Type' => 'application/problem+json; charset=utf-8'],
        );
    }
}
