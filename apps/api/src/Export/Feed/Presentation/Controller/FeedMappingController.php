<?php

declare(strict_types=1);

namespace App\Export\Feed\Presentation\Controller;

use App\Catalog\Contracts\Service\AttributeCatalogReader;
use App\Export\Feed\Application\Mapping\FeedMappingService;
use App\Export\Feed\Application\Mapping\InvalidMappingException;
use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Repository\FeedProfileRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use App\Shared\Application\UserIdentityAware;
use App\Shared\Domain\Tenant;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Feed mapper API (ADR-0023 §6.4, XMLF-P3-01) — the backend for the mapper
 * step (XMLF-P5-03). `GET` projects the mapper state (descriptor slots + their
 * current mapping, the tenant PIM attribute catalog, coverage counters and
 * advisory type warnings); `PUT` validates and persists a new mapping set.
 *
 * The attribute catalog is read through the cross-BC
 * {@see AttributeCatalogReader} seam; feeds never reach Catalog internals.
 * Auto-registered into OpenAPI by CustomRouteOpenApiFactory (ADR-0020).
 *
 * The channel `column_aliases` pre-fill (ADR-0018) is a deliberate follow-up:
 * it needs a Channel contracts-layer publication-alias reader (a second bounded
 * context / Plan Mode change) and the built-in templates already seed sensible
 * default mappings (XMLF-P2-02), so the mapper ships without it.
 */
final class FeedMappingController
{
    public function __construct(
        private readonly FeedProfileRepositoryInterface $feeds,
        private readonly AttributeCatalogReader $attributes,
        private readonly FeedMappingService $mapper,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
    ) {
    }

    #[Route(path: '/api/feeds/{id}/mapping', name: 'pim_feeds_mapping_get', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all', anyOf: [
        'exports.view_all',
        'settings.integrations.manage',
    ])]
    public function get(string $id): JsonResponse
    {
        $tenant = $this->resolveTenant();
        $feed = $this->loadOrFail($id);

        return new JsonResponse($this->mapper->view($feed, $this->attributes->findAllByTenant($tenant->getId())));
    }

    #[Route(path: '/api/feeds/{id}/mapping', name: 'pim_feeds_mapping_put', methods: ['PUT'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'integration', action: 'admin', anyOf: [
        'integration.admin',
        'settings.integrations.manage',
    ])]
    public function put(string $id, Request $request): JsonResponse
    {
        $tenant = $this->resolveTenant();
        $feed = $this->loadOrFail($id);
        $catalog = $this->attributes->findAllByTenant($tenant->getId());

        try {
            $this->mapper->applyUpdate($feed, $this->decodeJson($request), $catalog);
        } catch (InvalidMappingException $error) {
            throw new BadRequestHttpException($error->getMessage(), $error);
        }
        $this->feeds->save($feed);

        return new JsonResponse($this->mapper->view($feed, $catalog));
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
            // Tenant isolation via RLS/TenantFilter; a miss is a 404 either way.
            throw new NotFoundHttpException(sprintf('Feed "%s" was not found.', $id));
        }

        return $feed;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(Request $request): array
    {
        $body = $request->getContent();
        if ('' === $body) {
            return [];
        }
        $decoded = json_decode($body, true);
        if (!\is_array($decoded)) {
            throw new BadRequestHttpException('Request body must be a JSON object.');
        }
        $payload = [];
        foreach ($decoded as $key => $value) {
            if (!\is_string($key)) {
                throw new BadRequestHttpException('Request body must be a JSON object (string keys).');
            }
            $payload[$key] = $value;
        }

        return $payload;
    }
}
