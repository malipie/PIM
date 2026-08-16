<?php

declare(strict_types=1);

namespace App\Export\Feed\Presentation\Controller;

use App\Export\Feed\Application\Delivery\FeedTokenService;
use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Repository\FeedProfileRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use App\Shared\Application\UserIdentityAware;
use App\Shared\Domain\Tenant;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Feed URL token lifecycle (ADR-0023 §6.9, XMLF-P3-04/P3-05).
 *
 * `POST /api/feeds/{id}/token` mints (or rotates) the token — the plaintext is
 * returned ONCE together with the public pull URL; only its HMAC is persisted.
 * `DELETE /api/feeds/{id}/token` revokes it (the public URL immediately 404s).
 * Auto-registered into OpenAPI by CustomRouteOpenApiFactory (ADR-0020).
 */
final class FeedTokenController
{
    public function __construct(
        private readonly FeedProfileRepositoryInterface $feeds,
        private readonly FeedTokenService $tokens,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
    ) {
    }

    #[Route(path: '/api/feeds/{id}/token', name: 'pim_feeds_token_mint', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'integration', action: 'admin', anyOf: [
        'integration.admin',
        'settings.integrations.manage',
    ])]
    public function mint(string $id): JsonResponse
    {
        $tenant = $this->resolveTenant();
        $feed = $this->loadOrFail($id);

        $token = $this->tokens->mint($feed);
        $this->feeds->save($feed);

        return new JsonResponse([
            'token' => $token, // shown once — only its HMAC is stored
            'url' => $this->pullUrl($tenant, $token),
        ], Response::HTTP_CREATED);
    }

    #[Route(path: '/api/feeds/{id}/token', name: 'pim_feeds_token_revoke', methods: ['DELETE'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'integration', action: 'admin', anyOf: [
        'integration.admin',
        'settings.integrations.manage',
    ])]
    public function revoke(string $id): Response
    {
        $this->resolveTenant();
        $feed = $this->loadOrFail($id);

        $this->tokens->revoke($feed);
        $this->feeds->save($feed);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    private function pullUrl(Tenant $tenant, string $token): string
    {
        return sprintf('/api/feeds/pull/%s/%s.xml', $tenant->getId()->toRfc4122(), $token);
    }

    private function resolveTenant(): Tenant
    {
        if (!$this->security->getUser() instanceof UserIdentityAware) {
            throw new AccessDeniedHttpException('Authenticated user identity required.');
        }
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new AccessDeniedHttpException('Tenant context required.');
        }

        return $tenant;
    }

    private function loadOrFail(string $id): FeedProfile
    {
        $feed = $this->feeds->findById(Uuid::fromString($id));
        if (null === $feed) {
            throw new NotFoundHttpException(sprintf('Feed "%s" was not found.', $id));
        }

        return $feed;
    }
}
