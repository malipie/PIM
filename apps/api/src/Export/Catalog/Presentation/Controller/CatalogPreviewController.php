<?php

declare(strict_types=1);

namespace App\Export\Catalog\Presentation\Controller;

use App\Export\Catalog\Application\Preview\CatalogPreviewService;
use App\Export\Catalog\Domain\Entity\CatalogProfile;
use App\Export\Catalog\Domain\Enum\CatalogTemplateKind;
use App\Export\Catalog\Domain\Mapping\CatalogFieldMapping;
use App\Export\Catalog\Domain\Repository\CatalogProfileRepositoryInterface;
use App\Export\Catalog\Domain\Template\TemplateNotAvailableException;
use App\Export\Contracts\CatalogProductScope;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use App\Shared\Application\UserIdentityAware;
use App\Shared\Domain\Tenant;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Catalog HTML live-preview API (ADR-0027, CPDF-P4-01) — backs the wizard's
 * live-preview iframe. `POST /api/catalogs/preview` renders a draft (template
 * kind + mappings from the request body, before the catalog is saved);
 * `GET /api/catalogs/{id}/preview` renders a saved catalog. Both return the
 * first N sample products as the rendered Twig HTML string (no PDF, no
 * CatalogRun, no cache). Mirrors {@see \App\Export\Feed\Presentation\Controller\FeedPreviewController}.
 * Auto-registered into OpenAPI by CustomRouteOpenApiFactory (ADR-0020).
 */
final class CatalogPreviewController
{
    public function __construct(
        private readonly CatalogProfileRepositoryInterface $catalogs,
        private readonly CatalogPreviewService $preview,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
    ) {
    }

    #[Route(path: '/api/catalogs/preview', name: 'pim_catalogs_preview_draft', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all')]
    public function draft(Request $request): JsonResponse
    {
        $this->resolveTenant();
        $payload = $this->decodeJson($request);

        $kind = $this->parseTemplateKind($payload);
        $objectTypeId = $this->parseUuid($payload, 'object_type_id');

        $branding = \is_array($payload['branding'] ?? null) ? $payload['branding'] : [];
        $fieldMappings = \is_array($payload['field_mappings'] ?? null)
            ? array_values(array_filter($payload['field_mappings'], \is_array(...)))
            : [];
        $mappings = CatalogFieldMapping::listFromArray($fieldMappings);

        $filter = \is_array($payload['filter'] ?? null) ? $payload['filter'] : null;
        $scope = new CatalogProductScope(
            objectTypeId: $objectTypeId,
            attributeCodes: $this->attributeCodes($mappings),
            locale: $this->optionalString($payload, 'locale'),
            channel: $this->optionalString($payload, 'publication_channel'),
            filter: $filter,
        );

        return $this->render($kind, $branding, $fieldMappings, $scope, $this->limit($payload));
    }

    #[Route(path: '/api/catalogs/{id}/preview', name: 'pim_catalogs_preview_saved', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all')]
    public function saved(string $id, Request $request): JsonResponse
    {
        $this->resolveTenant();
        $catalog = $this->loadOrFail($id);

        $mappings = CatalogFieldMapping::listFromArray($catalog->getFieldMappings());
        $scope = new CatalogProductScope(
            objectTypeId: $catalog->getObjectTypeId(),
            attributeCodes: $this->attributeCodes($mappings),
            locale: $catalog->getLocale(),
            channel: $catalog->getPublicationChannel(),
            filter: $catalog->getFilter(),
        );

        $limit = $request->query->getInt('limit', CatalogPreviewService::DEFAULT_LIMIT);

        return $this->render(
            $catalog->getTemplateKind(),
            $catalog->getBranding(),
            $catalog->getFieldMappings(),
            $scope,
            $limit,
        );
    }

    /**
     * @param array<mixed, mixed>       $branding
     * @param list<array<mixed, mixed>> $fieldMappings
     */
    private function render(
        CatalogTemplateKind $kind,
        array $branding,
        array $fieldMappings,
        CatalogProductScope $scope,
        int $limit,
    ): JsonResponse {
        try {
            $result = $this->preview->preview($kind, $branding, $fieldMappings, $scope, $limit);
        } catch (TemplateNotAvailableException $error) {
            // the grid archetype is not renderable yet (CPDF-P6-02).
            throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY, $error->getMessage(), $error);
        }

        return new JsonResponse($result);
    }

    /**
     * @param list<CatalogFieldMapping> $mappings
     *
     * @return list<string>
     */
    private function attributeCodes(array $mappings): array
    {
        $codes = [];
        foreach ($mappings as $mapping) {
            if ('attribute' === $mapping->sourceKind && null !== $mapping->sourceRef) {
                $codes[$mapping->sourceRef] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function parseTemplateKind(array $payload): CatalogTemplateKind
    {
        $value = $payload['template_kind'] ?? null;
        if (!\is_string($value) || null === ($kind = CatalogTemplateKind::tryFrom($value))) {
            throw new BadRequestHttpException('template_kind is required (one of: sheet, grid, pricelist).');
        }

        return $kind;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function parseUuid(array $payload, string $key): Uuid
    {
        $value = $payload[$key] ?? null;
        if (!\is_string($value) || !Uuid::isValid($value)) {
            throw new BadRequestHttpException(sprintf('%s is required (UUID).', $key));
        }

        return Uuid::fromString($value);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function limit(array $payload): int
    {
        return \is_int($payload['limit'] ?? null) ? $payload['limit'] : CatalogPreviewService::DEFAULT_LIMIT;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function optionalString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
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
            // Tenant isolation via RLS/TenantFilter; a miss is a 404 either way.
            throw new NotFoundHttpException(sprintf('Catalog "%s" was not found.', $id));
        }

        return $catalog;
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
