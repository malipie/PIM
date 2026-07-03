<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RefreshTokenRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * DP-02 (#2032) — `POST /api/users/{id}/password`: an admin sets a new
 * password for another user directly from the panel.
 *
 * Closes the lifecycle gap for panel-managed accounts whose email is a
 * login identifier rather than a reachable mailbox (the manual-create
 * flow #867 never verifies deliverability): the magic-link reset
 * (PasswordResetController) is useless for them, and PATCH /api/users/{id}
 * only updates roles.
 *
 * Request body:
 *   {
 *     "password": "string (required, min 12 chars)",
 *     "force_password_change": true   // optional, default true
 *   }
 *
 * Responses:
 *   - 204 No Content — password replaced, user's refresh tokens revoked.
 *   - 400 Problem Details — payload missing or password too short.
 *   - 403 — caller lacks the users-admin permission.
 *   - 404 — unknown id or cross-tenant target (never distinguishable).
 *   - 409 — self-target: admins change their own password via
 *     /api/me/change-password (which re-authenticates with the current one).
 *
 * Every still-active refresh token of the target is revoked so the
 * previous credential holder cannot ride an old session past the reset;
 * the short-lived JWT (1h) is the only remaining exposure window.
 * Permission gate mirrors the rest of the users CRUD surface
 * (`user.admin`, Phase 6 retrofits onto `settings.users.manage`).
 */
final readonly class UserSetPasswordController
{
    private const int MIN_LENGTH = 12;

    public function __construct(
        private Security $security,
        private UserRepositoryInterface $users,
        private RefreshTokenRepositoryInterface $refreshTokens,
        private UserPasswordHasherInterface $hasher,
    ) {
    }

    #[Route(path: '/api/users/{id}/password', methods: ['POST'], name: 'api_users_set_password', requirements: ['id' => '[0-9a-f-]{36}'])]
    #[RequiresPermission(module: 'user', action: 'admin')]
    public function __invoke(string $id, Request $request): Response
    {
        $caller = $this->security->getUser();
        if (!$caller instanceof User) {
            return $this->problem(Response::HTTP_UNAUTHORIZED, 'Unauthorized', 'No authenticated user.');
        }

        $target = $this->loadTargetInSameTenant($caller, $id);
        if ($target instanceof JsonResponse) {
            return $target;
        }

        if ($caller->getId()->equals($target->getId())) {
            return $this->problem(
                Response::HTTP_CONFLICT,
                'Self-reset forbidden',
                'Change your own password via /api/me/change-password.',
                ['code' => 'self_reset'],
            );
        }

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', 'Request body must be JSON.');
        }

        $password = $payload['password'] ?? null;
        if (!\is_string($password) || '' === $password) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', '`password` is a required string.');
        }
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return $this->problem(
                Response::HTTP_BAD_REQUEST,
                'Bad Request',
                \sprintf('password must be at least %d characters.', self::MIN_LENGTH),
                ['min_length' => self::MIN_LENGTH],
            );
        }
        $forceChange = \array_key_exists('force_password_change', $payload)
            ? (bool) $payload['force_password_change']
            : true;

        $target->changePassword($this->hasher->hashPassword($target, $password));
        if ($forceChange) {
            $target->markPasswordChangeRequired();
        } else {
            $target->clearPasswordChangeRequired();
        }
        $this->users->save($target);

        // Cut off any session still riding the old credential.
        $this->refreshTokens->revokeAllForUser($target->getId(), new DateTimeImmutable());

        return new Response(null, Response::HTTP_NO_CONTENT);
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
