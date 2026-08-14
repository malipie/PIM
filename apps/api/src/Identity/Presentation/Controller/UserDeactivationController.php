<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Application\LastAdminGuard;
use App\Identity\Application\UserListResponseBuilder;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Exception\LastAdminProtectionException;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * RBAC-P5-004 (#694) — deactivate / reactivate endpoints for the
 * Settings → Users 3-dot menu.
 *
 *   - POST /api/users/{id}/deactivate → User::disable() if guarded
 *     constraints hold (not self, not last admin); returns 200 with
 *     the refreshed projection so the FE can replace the row without
 *     a list refetch.
 *   - POST /api/users/{id}/reactivate → User::enable() — simpler,
 *     only the tenant-boundary check applies.
 *
 * Cross-tenant safety: TenantFilter scopes the repository lookup; an
 * explicit `getTenant()->getId()` equality fence on the principal +
 * subject is enforced as defence in depth.
 *
 * Permission gate: `user.admin` — the same retrofit-pending proxy used
 * by `/api/users` (RBAC-P5-001), so super_admin / tenant_owner reach
 * the endpoint while Catalog Manager / Viewer get 403. Phase 6 (#720+)
 * migrates the attribute onto PRD §3.2 `settings.users.manage`.
 *
 * Existing-session invalidation (PRD §3 spec line *„Existing sessions
 * invalidated"*) is deferred to the session-management UI follow-up —
 * it needs a `RefreshTokenRepositoryInterface::revokeAllForUser()`
 * method that the repo does not expose yet. JWT TTL is short (1h), so
 * the practical exposure window is small.
 */
final readonly class UserDeactivationController
{
    public function __construct(
        private Security $security,
        private UserRepositoryInterface $users,
        private UserListResponseBuilder $builder,
        private LastAdminGuard $lastAdminGuard,
    ) {
    }

    #[Route(path: '/api/users/{id}/deactivate', methods: ['POST'], name: 'api_users_deactivate', requirements: ['id' => '[0-9a-f-]{36}'])]
    #[RequiresPermission(module: 'user', action: 'admin', anyOf: ['user.admin', 'settings.users.manage'])]
    public function deactivate(string $id): JsonResponse
    {
        $caller = $this->callerOrUnauthorized();
        if ($caller instanceof JsonResponse) {
            return $caller;
        }

        $target = $this->loadTargetInSameTenant($caller, $id);
        if ($target instanceof JsonResponse) {
            return $target;
        }

        if ($caller->getId()->equals($target->getId())) {
            return $this->problem(
                Response::HTTP_CONFLICT,
                'Self-deactivation forbidden',
                'You cannot deactivate your own account.',
            );
        }

        try {
            $this->lastAdminGuard->ensureNotLastAdmin($target);
        } catch (LastAdminProtectionException $error) {
            return $this->problem(
                Response::HTTP_CONFLICT,
                'Last administrator protection',
                $error->getMessage(),
                ['code' => 'last_admin'],
            );
        }

        if ($target->isActive()) {
            $target->disable();
            $this->users->save($target);
        }

        return new JsonResponse($this->builder->buildOne($target));
    }

    #[Route(path: '/api/users/{id}/reactivate', methods: ['POST'], name: 'api_users_reactivate', requirements: ['id' => '[0-9a-f-]{36}'])]
    #[RequiresPermission(module: 'user', action: 'admin', anyOf: ['user.admin', 'settings.users.manage'])]
    public function reactivate(string $id): JsonResponse
    {
        $caller = $this->callerOrUnauthorized();
        if ($caller instanceof JsonResponse) {
            return $caller;
        }

        $target = $this->loadTargetInSameTenant($caller, $id);
        if ($target instanceof JsonResponse) {
            return $target;
        }

        if (!$target->isActive()) {
            $target->enable();
            $this->users->save($target);
        }

        return new JsonResponse($this->builder->buildOne($target));
    }

    private function callerOrUnauthorized(): User|JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return $this->problem(Response::HTTP_UNAUTHORIZED, 'Unauthorized', 'No authenticated user.');
        }

        return $user;
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
            // Defence in depth — TenantFilter should already scope this,
            // but a hand-crafted UUID across tenants must never escalate.
            return $this->problem(Response::HTTP_NOT_FOUND, 'Not Found', 'User not found.');
        }

        return $target;
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
