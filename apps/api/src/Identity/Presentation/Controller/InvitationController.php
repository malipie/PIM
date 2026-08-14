<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Application\InvitationService;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Entity\User;
use App\Shared\Application\TenantContext;
use LogicException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use const DATE_ATOM;

/**
 * RBAC-P2-008 (#657) — magic-link invitation endpoints.
 *
 * POST /api/invitations         — admin/owner creates invitation
 * POST /api/invitations/{token}/accept — accept invitation (public)
 *
 * Dev mode: \`create\` returns the plaintext token in response body so the
 * operator can copy/paste into the accept call. Production email send
 * (Symfony Mailer + Twig template) is a follow-up ticket once mailer
 * infra ships.
 */
final class InvitationController extends AbstractController
{
    use DevTokenExposure;

    public function __construct(
        private readonly InvitationService $invitations,
        private readonly TenantContext $tenantContext,
        private readonly string $devTokenEnvironment,
        private readonly RateLimiterFactoryInterface $invitationAcceptLimiter,
    ) {
    }

    // AUD-024 / W1-12: align the gate with the implemented permission
    // catalogue. `settings.users.manage` is a PRD §3.2 label that is never
    // seeded (RbacSeeder seeds the RbacMatrix `{resource}.{action}` cross
    // product), so the original gate denied EVERY principal — including a
    // full Super Admin / Tenant Owner — making invitation creation dead.
    // `user.admin` is the code the sibling user-management endpoints use
    // (UserCreateController, InvitationActionsController::revoke/resend).
    #[Route(path: '/api/invitations', methods: ['POST'], name: 'api_invitations_create')]
    #[RequiresPermission(module: 'user', action: 'admin', anyOf: ['user.admin', 'settings.users.manage'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function create(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new BadRequestHttpException('Tenant context required.');
        }

        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new BadRequestHttpException('User principal required (JWT auth).');
        }

        /** @var array{email?: string, role_code?: string} $payload */
        $payload = (array) json_decode($request->getContent(), true);
        $email = $payload['email'] ?? '';
        $roleCode = $payload['role_code'] ?? '';
        if ('' === $email || '' === $roleCode) {
            throw new BadRequestHttpException('email and role_code are required.');
        }

        try {
            $result = $this->invitations->create($tenant, $email, $roleCode, $user);
        } catch (RuntimeException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        return new JsonResponse([
            'invitation_id' => $result['invitation']->getId()->toRfc4122(),
            'email' => $result['invitation']->getEmail(),
            'expires_at' => $result['invitation']->getExpiresAt()->format(DATE_ATOM),
            // Dev/test: token shipped in response so operator can test the
            // accept flow before mailer infra ships. Production omits this
            // field entirely (AUD-007 / #1577) and relies on the Mailer.
            ...$this->devTokenPayload($result['token']),
        ], 201);
    }

    #[Route(path: '/api/invitations/{token}/accept', methods: ['POST'], name: 'api_invitations_accept', requirements: ['token' => '[a-f0-9]{64}'])]
    #[\App\Identity\Contracts\Attribute\NoPermissionRequired(reason: 'Magic-link accept is open by design — token IS the auth factor; account does not exist yet.')]
    public function accept(string $token, Request $request): JsonResponse
    {
        // AUD-030 (W2-12) — per-IP anti-bruteforce on a PUBLIC_ACCESS endpoint
        // where the 64-hex token IS the auth factor. Consume before validating
        // the token / hashing the password so the limiter clamps a token-space
        // brute or a hashing-DoS loop. Keyed by IP only — no pre-auth
        // identifier exists (the account is created by this very call) and an
        // honest invitee accepts exactly once.
        $consumed = $this->invitationAcceptLimiter
            ->create($request->getClientIp() ?? 'unknown')
            ->consume();
        if (!$consumed->isAccepted()) {
            $secondsUntilReset = max(1, $consumed->getRetryAfter()->getTimestamp() - time());

            throw new TooManyRequestsHttpException(
                $secondsUntilReset,
                'Too many invitation-accept attempts. Try again later.',
                null,
                0,
                ['Retry-After' => (string) $secondsUntilReset],
            );
        }

        /** @var array{password?: string} $payload */
        $payload = (array) json_decode($request->getContent(), true);
        $password = $payload['password'] ?? '';
        if ('' === $password || \strlen($password) < 8) {
            throw new BadRequestHttpException('password (min 8 chars) is required.');
        }

        try {
            $user = $this->invitations->accept($token, $password);
        } catch (LogicException $e) {
            // already-accepted / revoked / expired (Invitation::accept enforces)
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        return new JsonResponse([
            'user_id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
            'status' => 'accepted',
        ], 201);
    }

    /**
     * RBAC-P5-017 (#707) — read-only inspect for the magic-link accept
     * page. The FE calls this before showing the password form so the
     * operator never types a password into a form that will fail on
     * submit (expired / revoked / already-accepted token).
     *
     * Public route — token IS the auth factor.
     */
    #[Route(path: '/api/invitations/{token}/verify', methods: ['GET'], name: 'api_invitations_verify', requirements: ['token' => '[a-f0-9]{64}'])]
    #[\App\Identity\Contracts\Attribute\NoPermissionRequired(reason: 'Magic-link verify is open by design — token IS the auth factor; account does not exist yet.')]
    public function verify(string $token): JsonResponse
    {
        $snapshot = $this->invitations->verify($token);
        $status = $snapshot['status'];

        $httpStatus = match ($status) {
            'valid' => 200,
            'accepted', 'expired', 'revoked' => 410,
            default => 404,
        };

        return new JsonResponse($snapshot, $httpStatus);
    }
}
