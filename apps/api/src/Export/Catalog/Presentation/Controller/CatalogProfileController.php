<?php

declare(strict_types=1);

namespace App\Export\Catalog\Presentation\Controller;

use App\Export\Catalog\Domain\Entity\CatalogProfile;
use App\Export\Catalog\Domain\Enum\CatalogStatus;
use App\Export\Catalog\Domain\Enum\CatalogTemplateKind;
use App\Export\Catalog\Domain\Repository\CatalogProfileRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use App\Shared\Application\UserIdentityAware;
use App\Shared\Domain\Tenant;
use DateTimeInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * CRUD for PDF catalog profiles (ADR-0027, CPDF-P1-03). Tenant-scoped custom
 * routes (CQRS, ADR-0012) — the catalog-world analogue of
 * {@see \App\Export\Feed\Presentation\Controller\FeedProfileController}, sharing
 * its export RBAC surface (`exports.view_all` on reads, `integration.admin` on
 * writes) so no new permission code is introduced. Auto-registered into OpenAPI
 * by CustomRouteOpenApiFactory (ADR-0020).
 *
 * The URL token hash is never serialized (CPDF public URL, P3); publication
 * config mints/rotates it via a dedicated endpoint outside this CRUD surface.
 */
final class CatalogProfileController
{
    public function __construct(
        private readonly CatalogProfileRepositoryInterface $catalogs,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
    ) {
    }

    #[Route(path: '/api/catalogs', name: 'pim_catalogs_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all')]
    public function list(): JsonResponse
    {
        [$tenant] = $this->resolveTenant();

        return new JsonResponse([
            'items' => array_map($this->serialize(...), $this->catalogs->findByTenant($tenant)),
        ]);
    }

    #[Route(path: '/api/catalogs', name: 'pim_catalogs_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    // #2881 — the writes follow the SAME code this controller's reads use
    // (`exports.view_all`), not `exports.run`. A first pass mapped them to
    // exports.run and CI caught the incoherence: `marketing` holds
    // exports.run but only exports.view_own, so it could create a PDF
    // catalog it could not then open. A write reachable by someone who
    // cannot read the surface is a worse gate than the legacy one it
    // replaced. Minting the public pull token is a secret and follows
    // `settings.integrations.manage` instead — see CatalogTokenController.
    #[RequiresPermission(module: 'integration', action: 'admin', anyOf: [
        'integration.admin',
        'exports.view_all',
    ])]
    public function create(Request $request): JsonResponse
    {
        [$tenant] = $this->resolveTenant();
        $payload = $this->decodeJson($request);

        $kind = $this->parseKind($payload);
        $code = $this->parseString($payload, 'code');
        $name = $this->parseString($payload, 'name');
        $objectTypeId = $this->parseUuid($payload, 'object_type_id');

        if (null !== $this->catalogs->findByTenantAndCode($tenant, $code)) {
            throw new BadRequestHttpException(sprintf('A catalog with code "%s" already exists.', $code));
        }

        $catalog = new CatalogProfile(
            code: $code,
            name: $name,
            templateKind: $kind,
            objectTypeId: $objectTypeId,
            branding: $this->parseArray($payload, 'branding'),
            fieldMappings: $this->parseMappings($payload),
            filter: $this->parseOptionalArray($payload, 'filter'),
            channelId: $this->parseOptionalUuid($payload, 'channel_id'),
            publicationChannel: $this->parseOptionalString($payload, 'publication_channel'),
            locale: $this->parseOptionalString($payload, 'locale'),
            renderer: $this->parseRenderer($payload),
        );
        $catalog->assignTenant($tenant);
        $this->catalogs->save($catalog);

        return new JsonResponse($this->serialize($catalog), Response::HTTP_CREATED);
    }

    #[Route(path: '/api/catalogs/{id}', name: 'pim_catalogs_get', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all')]
    public function get(string $id): JsonResponse
    {
        return new JsonResponse($this->serialize($this->loadOrFail($id)));
    }

    #[Route(path: '/api/catalogs/{id}', name: 'pim_catalogs_patch', methods: ['PATCH'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'integration', action: 'admin', anyOf: [
        'integration.admin',
        'exports.view_all',
    ])]
    public function patch(string $id, Request $request): JsonResponse
    {
        $catalog = $this->loadOrFail($id);
        $payload = $this->decodeJson($request);

        if (\array_key_exists('name', $payload)) {
            $catalog->rename($this->parseString($payload, 'name'));
        }
        if (\array_key_exists('template_kind', $payload)) {
            $catalog->changeTemplateKind($this->parseKind($payload));
        }
        if (\array_key_exists('branding', $payload) && \is_array($payload['branding'])) {
            $catalog->updateBranding($payload['branding']);
        }
        if (\array_key_exists('field_mappings', $payload) && \is_array($payload['field_mappings'])) {
            $catalog->updateFieldMappings(array_values(array_filter($payload['field_mappings'], \is_array(...))));
        }
        if (\array_key_exists('filter', $payload)) {
            $catalog->updateFilter(\is_array($payload['filter']) ? $payload['filter'] : null);
        }
        if (\array_key_exists('renderer', $payload)) {
            $catalog->setRenderer($this->parseRenderer($payload));
        }
        if (\array_key_exists('status', $payload)) {
            $this->applyStatus($catalog, $payload);
        }
        // Scope block — channel drives per-channel value resolution (?channel=);
        // absent keys keep their current values.
        if (\array_key_exists('channel_id', $payload)
            || \array_key_exists('publication_channel', $payload)
            || \array_key_exists('locale', $payload)
        ) {
            $catalog->setScope(
                \array_key_exists('channel_id', $payload) ? $this->parseOptionalUuid($payload, 'channel_id') : $catalog->getChannelId(),
                \array_key_exists('publication_channel', $payload) ? $this->parseOptionalString($payload, 'publication_channel') : $catalog->getPublicationChannel(),
                \array_key_exists('locale', $payload) ? $this->parseOptionalString($payload, 'locale') : $catalog->getLocale(),
            );
        }

        $this->catalogs->save($catalog);

        return new JsonResponse($this->serialize($catalog));
    }

    #[Route(path: '/api/catalogs/{id}', name: 'pim_catalogs_delete', methods: ['DELETE'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'integration', action: 'admin', anyOf: [
        'integration.admin',
        'exports.view_all',
    ])]
    public function delete(string $id): Response
    {
        $this->catalogs->remove($this->loadOrFail($id));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array{Tenant}
     */
    private function resolveTenant(): array
    {
        if (!$this->security->getUser() instanceof UserIdentityAware) {
            throw new AccessDeniedHttpException('Authenticated user identity required.');
        }
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new AccessDeniedHttpException('Tenant context required.');
        }

        return [$tenant];
    }

    private function loadOrFail(string $id): CatalogProfile
    {
        $this->resolveTenant();
        $catalog = $this->catalogs->findById(Uuid::fromString($id));
        if (null === $catalog) {
            // Tenant isolation via RLS/TenantFilter; a miss is a 404 either way.
            throw new NotFoundHttpException(sprintf('Catalog "%s" was not found.', $id));
        }

        return $catalog;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyStatus(CatalogProfile $catalog, array $payload): void
    {
        $value = $payload['status'] ?? null;
        $status = \is_string($value) ? CatalogStatus::tryFrom($value) : null;
        if (null === $status) {
            throw new BadRequestHttpException('status must be one of active|paused|error.');
        }
        match ($status) {
            CatalogStatus::Paused => $catalog->pause(),
            CatalogStatus::Active => $catalog->resume(),
            CatalogStatus::Error => $catalog->markError(),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function parseKind(array $payload): CatalogTemplateKind
    {
        $value = $payload['template_kind'] ?? null;
        $kind = \is_string($value) ? CatalogTemplateKind::tryFrom($value) : null;
        if (null === $kind) {
            throw new BadRequestHttpException('template_kind is required (sheet|grid|pricelist).');
        }

        return $kind;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function parseRenderer(array $payload): string
    {
        $value = $payload['renderer'] ?? 'auto';

        return \is_string($value) && '' !== $value ? $value : 'auto';
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<mixed, mixed>
     */
    private function parseArray(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;

        return \is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<mixed, mixed>|null
     */
    private function parseOptionalArray(array $payload, string $key): ?array
    {
        $value = $payload[$key] ?? null;

        return \is_array($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<mixed, mixed>>
     */
    private function parseMappings(array $payload): array
    {
        $value = $payload['field_mappings'] ?? null;

        return \is_array($value)
            ? array_values(array_filter($value, \is_array(...)))
            : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function parseString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw new BadRequestHttpException(sprintf('%s is required (non-empty string).', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function parseOptionalString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
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
    private function parseOptionalUuid(array $payload, string $key): ?Uuid
    {
        $value = $payload[$key] ?? null;
        if (null === $value || '' === $value) {
            return null;
        }
        if (!\is_string($value) || !Uuid::isValid($value)) {
            throw new BadRequestHttpException(sprintf('%s must be a UUID when provided.', $key));
        }

        return Uuid::fromString($value);
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

    /**
     * @return array<string, mixed>
     */
    private function serialize(CatalogProfile $catalog): array
    {
        return [
            'id' => $catalog->getId()->toRfc4122(),
            'code' => $catalog->getCode(),
            'name' => $catalog->getName(),
            'template_kind' => $catalog->getTemplateKind()->value,
            'object_type_id' => $catalog->getObjectTypeId()->toRfc4122(),
            'status' => $catalog->getStatus()->value,
            'branding' => $catalog->getBranding(),
            'field_mappings' => $catalog->getFieldMappings(),
            'filter' => $catalog->getFilter(),
            'channel_id' => $catalog->getChannelId()?->toRfc4122(),
            'publication_channel' => $catalog->getPublicationChannel(),
            'locale' => $catalog->getLocale(),
            'renderer' => $catalog->getRenderer(),
            'cached_file_size' => $catalog->getCachedFileSize(),
            'cached_page_count' => $catalog->getCachedPageCount(),
            'cached_at' => $catalog->getCachedAt()?->format(DateTimeInterface::ATOM),
            // The plaintext token is never persisted; the hub only needs to know
            // whether a URL exists to offer rotate vs mint copy.
            'has_token' => null !== $catalog->getTokenHash(),
            'created_at' => $catalog->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updated_at' => $catalog->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ];
    }
}
