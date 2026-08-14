<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Application\PermissionResolverInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\ApiTokenRepositoryInterface;
use DateTimeInterface;
use InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * RBAC-P5-011 (#701) — `DELETE /api/api-tokens/{id}` revokes a token.
 *
 * Revocation is non-destructive (sets `revoked_at` timestamp); the row
 * stays in the table so audit logs keep their FK references and the
 * Settings → API tokens list can still display revoked entries with
 * their last-used metadata. The `RbacApiTokenAuthenticator` rejects
 * any incoming `Authorization: Token ...` whose hash maps to a row
 * with `revoked_at IS NOT NULL`.
 *
 * Two scopes share one endpoint:
 *   - the token's owner can always revoke their own token
 *     (`api_tokens.own.crud` per PRD §3.2),
 *   - holders of `api_tokens.all.view_revoke` can revoke any token
 *     in their tenant.
 *
 * Cross-tenant safety: TenantFilter scopes the lookup; defence-in-
 * depth equality check on caller + subject tenant ids forbids
 * surfacing a token from another tenant even if the filter misfires.
 *
 * Already-revoked tokens are idempotent — re-revoking returns 200
 * with the same response (Frontend caches the list and might POST
 * twice if the operator double-clicks).
 */
final readonly class RevokeApiTokenController
{
    public function __construct(
        private Security $security,
        private ApiTokenRepositoryInterface $tokens,
        private PermissionResolverInterface $resolver,
    ) {
    }

    #[Route(path: '/api/api-tokens/{id}', methods: ['DELETE'], name: 'api_api_tokens_revoke', requirements: ['id' => '[0-9a-f-]{36}'])]
    #[RequiresPermission(module: 'user', action: 'read', anyOf: ['user.read', 'api_tokens.own.crud'])]
    public function __invoke(string $id): JsonResponse
    {
        $caller = $this->security->getUser();
        if (!$caller instanceof User) {
            return $this->problem(Response::HTTP_UNAUTHORIZED, 'Unauthorized', 'No authenticated user.');
        }

        try {
            $uuid = Uuid::fromString($id);
        } catch (InvalidArgumentException) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'Not Found', 'Token not found.');
        }

        $token = $this->tokens->findById($uuid);
        if (null === $token) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'Not Found', 'Token not found.');
        }

        if (!$caller->getTenant()->getId()->equals($token->getTenantId())) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'Not Found', 'Token not found.');
        }

        $owns = $caller->getId()->equals($token->getUserId());
        $mayManageAll = $this->resolver->resolve($caller)->has('api_tokens.all.view_revoke');
        if (!$owns && !$mayManageAll) {
            return $this->problem(
                Response::HTTP_FORBIDDEN,
                'Forbidden',
                'You can revoke only your own API tokens unless you hold the api_tokens.all.view_revoke permission.',
            );
        }

        if (!$token->isRevoked()) {
            $token->revoke();
            $this->tokens->save($token);
        }

        return new JsonResponse([
            'id' => $token->getId()->toRfc4122(),
            'status' => 'revoked',
            'revoked_at' => $token->getRevokedAt()?->format(DateTimeInterface::ATOM),
        ]);
    }

    private function problem(int $status, string $title, string $detail): JsonResponse
    {
        return new JsonResponse(
            [
                'type' => 'about:blank',
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
            ],
            $status,
            ['Content-Type' => 'application/problem+json; charset=utf-8'],
        );
    }
}
