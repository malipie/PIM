<?php

declare(strict_types=1);

namespace App\Export\Catalog\Presentation\Controller;

use App\Export\Catalog\Application\Delivery\CatalogTokenService;
use App\Export\Catalog\Domain\Entity\CatalogProfile;
use App\Export\Catalog\Domain\Repository\CatalogProfileRepositoryInterface;
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
 * Catalog PDF URL token lifecycle (ADR-0027 §6.5, CPDF-P3-02).
 *
 * `POST /api/catalogs/{id}/token` mints (or rotates) the token — the plaintext
 * is returned ONCE together with the public pull URL; only its HMAC is
 * persisted. `DELETE /api/catalogs/{id}/token` revokes it (the public URL
 * immediately 404s). Reuses the export/integration RBAC (`integration.admin`,
 * same grant as the catalog CRUD writes). Auto-registered into OpenAPI by
 * CustomRouteOpenApiFactory (ADR-0020). Mirrors {@see \App\Export\Feed\Presentation\Controller\FeedTokenController}.
 */
final class CatalogTokenController
{
    public function __construct(
        private readonly CatalogProfileRepositoryInterface $catalogs,
        private readonly CatalogTokenService $tokens,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
    ) {
    }

    #[Route(path: '/api/catalogs/{id}/token', name: 'pim_catalogs_token_mint', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'integration', action: 'admin', anyOf: [
        'integration.admin',
        'exports.run',
    ])]
    public function mint(string $id): JsonResponse
    {
        $tenant = $this->resolveTenant();
        $catalog = $this->loadOrFail($id);

        $token = $this->tokens->mint($catalog);
        $this->catalogs->save($catalog);

        return new JsonResponse([
            'token' => $token, // shown once — only its HMAC is stored
            'url' => $this->pullUrl($tenant, $token),
        ], Response::HTTP_CREATED);
    }

    #[Route(path: '/api/catalogs/{id}/token', name: 'pim_catalogs_token_revoke', methods: ['DELETE'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'integration', action: 'admin', anyOf: [
        'integration.admin',
        'exports.run',
    ])]
    public function revoke(string $id): Response
    {
        $this->resolveTenant();
        $catalog = $this->loadOrFail($id);

        $this->tokens->revoke($catalog);
        $this->catalogs->save($catalog);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    private function pullUrl(Tenant $tenant, string $token): string
    {
        return sprintf('/api/catalogs/pull/%s/%s.pdf', $tenant->getId()->toRfc4122(), $token);
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

    private function loadOrFail(string $id): CatalogProfile
    {
        $catalog = $this->catalogs->findById(Uuid::fromString($id));
        if (null === $catalog) {
            throw new NotFoundHttpException(sprintf('Catalog "%s" was not found.', $id));
        }

        return $catalog;
    }
}
