<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Application\InvitationService;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\InvitationRepositoryInterface;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use DateTimeInterface;
use InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * UI polish #848 — revoke + resend actions on pending invitations
 * surfaced in the Settings → Users list (the "Invited" rows from
 * UserListResponseBuilder).
 *
 *   - POST   /api/invitations/{id}/revoke — flips the invitation to
 *           revoked status; the accept-link stops working immediately
 *   - POST   /api/invitations/{id}/resend — revokes the current
 *           invitation and creates a fresh one with a new TTL window,
 *           triggering the mailer again
 *
 * Both gated by `user.admin` (same surface as user invite — the
 * operator who can invite can also manage pending invitations).
 */
final readonly class InvitationActionsController
{
    use DevTokenExposure;

    public function __construct(
        private Security $security,
        private InvitationRepositoryInterface $invitations,
        private RoleRepositoryInterface $roles,
        private InvitationService $service,
        private string $devTokenEnvironment,
    ) {
    }

    #[Route(
        path: '/api/invitations/{id}/revoke',
        methods: ['POST'],
        name: 'api_invitations_revoke',
        requirements: ['id' => '[0-9a-f-]{36}'],
    )]
    #[RequiresPermission(module: 'user', action: 'admin', anyOf: ['user.admin', 'settings.users.manage'])]
    public function revoke(string $id): JsonResponse
    {
        $caller = $this->callerOrUnauthorized();
        if ($caller instanceof JsonResponse) {
            return $caller;
        }

        try {
            $uuid = Uuid::fromString($id);
        } catch (InvalidArgumentException) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'Not Found', 'Invitation not found.');
        }

        $invitation = $this->invitations->findById($uuid);
        if (null === $invitation || !$invitation->getTenantId()->equals($caller->getTenant()->getId())) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'Not Found', 'Invitation not found.');
        }
        if (null !== $invitation->getRevokedAt()) {
            return new JsonResponse(['revoked' => true]);
        }
        if (null !== $invitation->getAcceptedAt()) {
            return $this->problem(
                Response::HTTP_CONFLICT,
                'Invitation already accepted',
                'Accepted invitations cannot be revoked — deactivate the user instead.',
                ['code' => 'invitation_already_accepted'],
            );
        }

        $this->service->revoke($uuid);

        return new JsonResponse(['revoked' => true]);
    }

    #[Route(
        path: '/api/invitations/{id}/resend',
        methods: ['POST'],
        name: 'api_invitations_resend',
        requirements: ['id' => '[0-9a-f-]{36}'],
    )]
    #[RequiresPermission(module: 'user', action: 'admin', anyOf: ['user.admin', 'settings.users.manage'])]
    public function resend(string $id): JsonResponse
    {
        $caller = $this->callerOrUnauthorized();
        if ($caller instanceof JsonResponse) {
            return $caller;
        }

        try {
            $uuid = Uuid::fromString($id);
        } catch (InvalidArgumentException) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'Not Found', 'Invitation not found.');
        }

        $invitation = $this->invitations->findById($uuid);
        if (null === $invitation || !$invitation->getTenantId()->equals($caller->getTenant()->getId())) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'Not Found', 'Invitation not found.');
        }
        if (null !== $invitation->getAcceptedAt()) {
            return $this->problem(
                Response::HTTP_CONFLICT,
                'Invitation already accepted',
                'Accepted invitations cannot be resent.',
                ['code' => 'invitation_already_accepted'],
            );
        }

        $role = $this->roles->findById($invitation->getRoleId());
        if (null === $role) {
            return $this->problem(
                Response::HTTP_CONFLICT,
                'Role missing',
                'The role attached to this invitation no longer exists.',
                ['code' => 'role_missing'],
            );
        }

        // Revoke + re-create so the resent invitation has a fresh
        // 7-day TTL and a fresh token (the original plaintext is
        // hashed in DB; we cannot resurrect it from the existing row).
        if (null === $invitation->getRevokedAt()) {
            $this->service->revoke($uuid);
        }

        $result = $this->service->create(
            tenant: $caller->getTenant(),
            email: $invitation->getEmail(),
            roleCode: $role->getCode(),
            invitedBy: $caller,
        );

        $newInvitation = $result['invitation'];

        return new JsonResponse([
            'invitation_id' => $newInvitation->getId()->toRfc4122(),
            'email' => $newInvitation->getEmail(),
            'expires_at' => $newInvitation->getExpiresAt()->format(DateTimeInterface::ATOM),
            // Dev/test only — production omits the plaintext token entirely
            // (AUD-007 / #1577); the resend re-triggers the mailer instead.
            ...$this->devTokenPayload($result['token']),
        ], Response::HTTP_CREATED);
    }

    private function callerOrUnauthorized(): User|JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return $this->problem(Response::HTTP_UNAUTHORIZED, 'Unauthorized', 'No authenticated user.');
        }

        return $user;
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
