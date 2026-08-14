<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Application\ApiTokenListResponseBuilder;
use App\Identity\Application\PermissionResolverInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\ApiTokenRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * RBAC-P5-009 (#699) — `GET /api/api-tokens` listing for the
 * Settings → API tokens page.
 *
 * Two scopes share one endpoint:
 *  - operator without `api_tokens.all.view_revoke` sees only their own
 *    tokens — `findByUser($caller->getId())`,
 *  - operator with `api_tokens.all.view_revoke` (Owner / Administrator
 *    in PRD §3.2 macierz) can request the tenant-wide view by passing
 *    `?scope=tenant` and gets every token issued inside their tenant
 *    paired with the owner email projection.
 *
 * Permission gate: `user.read` (legacy RbacMatrix, super_admin + viewer
 * carry it), but the tenant-wide flag is honoured only when the caller
 * additionally holds `api_tokens.all.view_revoke`. Phase 6 #720+
 * retrofit migrates the gate onto PRD §3.2 codes wholesale.
 *
 * Response shape mirrors {@see UsersListController} so the admin data-
 * provider unwraps `member` / `totalItems` without a custom branch.
 */
final readonly class ApiTokensListController
{
    public function __construct(
        private Security $security,
        private ApiTokenRepositoryInterface $tokens,
        private UserRepositoryInterface $users,
        private ApiTokenListResponseBuilder $builder,
        private PermissionResolverInterface $resolver,
    ) {
    }

    #[Route(path: '/api/api-tokens', methods: ['GET'], name: 'api_api_tokens_list')]
    #[RequiresPermission(module: 'user', action: 'read', anyOf: ['user.read', 'api_tokens.own.crud'])]
    public function __invoke(Request $request): JsonResponse
    {
        $caller = $this->security->getUser();
        if (!$caller instanceof User) {
            return new JsonResponse(
                [
                    'type' => 'about:blank',
                    'title' => 'Unauthorized',
                    'status' => Response::HTTP_UNAUTHORIZED,
                    'detail' => 'No authenticated user.',
                ],
                Response::HTTP_UNAUTHORIZED,
                ['Content-Type' => 'application/problem+json; charset=utf-8'],
            );
        }

        $scope = $request->query->get('scope', 'own');
        $tenantWide = 'tenant' === $scope;
        if ($tenantWide && !$this->mayManageAllTokens($caller)) {
            // Caller asked for tenant scope but lacks the broader
            // permission — fall back to their own tokens silently so
            // the UI can render the section without flickering 403s.
            $tenantWide = false;
        }

        $tokens = $tenantWide
            ? $this->tokens->findByTenant($caller->getTenant()->getId())
            : $this->tokens->findByUser($caller->getId());

        $ownerEmails = [];
        if ($tenantWide) {
            $userIds = [];
            foreach ($tokens as $token) {
                $userIds[$token->getUserId()->toRfc4122()] = true;
            }
            foreach (array_keys($userIds) as $userId) {
                $owner = $this->users->findById(Uuid::fromString($userId));
                if (null !== $owner) {
                    $ownerEmails[$userId] = $owner->getEmail();
                }
            }
        } else {
            $ownerEmails[$caller->getId()->toRfc4122()] = $caller->getEmail();
        }

        $member = $this->builder->buildList($tokens, $ownerEmails);

        return new JsonResponse([
            'member' => $member,
            'totalItems' => \count($member),
            'meta' => [
                'scope' => $tenantWide ? 'tenant' : 'own',
                'can_view_all' => $this->mayManageAllTokens($caller),
            ],
        ]);
    }

    private function mayManageAllTokens(User $caller): bool
    {
        return $this->resolver->resolve($caller)->has('api_tokens.all.view_revoke');
    }
}
