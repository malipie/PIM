<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Entity\ApiToken;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\ApiTokenRepositoryInterface;
use App\Identity\Infrastructure\Security\RbacApiTokenAuthenticator;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RBAC-P5-010 (#700) — `POST /api/api-tokens` mints a new API token for
 * the authenticated user.
 *
 * Wraps the same Application logic the CLI `cortex:apitoken:create`
 * command uses (RBAC-P2-003 #652), so the wire-format token and the
 * audit story stay in sync between operator paths.
 *
 * Response shape (single occurrence of the plaintext token):
 *   {
 *     "id":            "uuid",
 *     "name":          "string",
 *     "token_last4":   "string",
 *     "scopes":        ["string"],
 *     "expires_at":    "ATOM | null",
 *     "created_at":    "ATOM",
 *     "plaintext":     "cortex_<tenant>_<random32>"
 *   }
 *
 * The plaintext field is the ONLY time the token leaves the server in
 * cleartext; subsequent reads expose only `token_last4`.
 *
 * Permission gate: `user.read` (legacy RbacMatrix) until Phase 6
 * retrofit migrates onto PRD §3.2 `api_tokens.own.crud`. Long-lived
 * tokens (no TTL) are allowed but flagged via audit (the underlying
 * AuditLogListener captures the kernel.response action).
 */
final readonly class CreateApiTokenController
{
    private const int MAX_NAME_LENGTH = 80;
    private const int MAX_TTL_DAYS = 3650;

    public function __construct(
        private Security $security,
        private ApiTokenRepositoryInterface $tokens,
    ) {
    }

    #[Route(path: '/api/api-tokens', methods: ['POST'], name: 'api_api_tokens_create')]
    #[RequiresPermission(module: 'user', action: 'read', anyOf: ['user.read', 'api_tokens.own.crud'])]
    public function __invoke(Request $request): JsonResponse
    {
        $caller = $this->security->getUser();
        if (!$caller instanceof User) {
            return $this->problem(Response::HTTP_UNAUTHORIZED, 'Unauthorized', 'No authenticated user.');
        }

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', 'Request body must be JSON.');
        }

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

        $scopesRaw = $payload['scopes'] ?? [];
        if (!\is_array($scopesRaw)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', '`scopes` must be an array of strings.');
        }
        $scopes = [];
        foreach ($scopesRaw as $scope) {
            if (\is_string($scope) && '' !== trim($scope)) {
                $scopes[] = trim($scope);
            }
        }
        if ([] === $scopes) {
            $scopes = ['read-only'];
        }

        $expiresAt = null;
        $ttlDaysRaw = $payload['ttl_days'] ?? null;
        if (null !== $ttlDaysRaw && '' !== $ttlDaysRaw) {
            if (!is_numeric($ttlDaysRaw)) {
                return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', '`ttl_days` must be numeric.');
            }
            $ttlDays = (int) $ttlDaysRaw;
            if ($ttlDays < 1 || $ttlDays > self::MAX_TTL_DAYS) {
                return $this->problem(
                    Response::HTTP_BAD_REQUEST,
                    'Bad Request',
                    \sprintf('`ttl_days` must be between 1 and %d.', self::MAX_TTL_DAYS),
                );
            }
            $expiresAt = new DateTimeImmutable(\sprintf('+%d days', $ttlDays));
        }

        $tenant = $caller->getTenant();
        $tenantShort = substr($tenant->getCode(), 0, 8);
        $plaintext = RbacApiTokenAuthenticator::generatePlaintext($tenantShort);
        $tokenHash = RbacApiTokenAuthenticator::hashFor($plaintext);
        $last4 = RbacApiTokenAuthenticator::last4($plaintext);

        $token = new ApiToken(
            tenantId: $tenant->getId(),
            userId: $caller->getId(),
            name: $name,
            tokenHash: $tokenHash,
            tokenLast4: $last4,
            scopes: $scopes,
            expiresAt: $expiresAt,
        );
        $this->tokens->save($token);

        return new JsonResponse(
            [
                'id' => $token->getId()->toRfc4122(),
                'name' => $token->getName(),
                'token_last4' => $token->getTokenLast4(),
                'scopes' => $token->getScopes(),
                'expires_at' => $token->getExpiresAt()?->format(DateTimeInterface::ATOM),
                'created_at' => $token->getCreatedAt()->format(DateTimeInterface::ATOM),
                'plaintext' => $plaintext,
            ],
            Response::HTTP_CREATED,
        );
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
