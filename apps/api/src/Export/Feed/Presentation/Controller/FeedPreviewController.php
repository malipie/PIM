<?php

declare(strict_types=1);

namespace App\Export\Feed\Presentation\Controller;

use App\Export\Contracts\FeedProductScope;
use App\Export\Feed\Application\Descriptor\FeedDescriptorGuard;
use App\Export\Feed\Application\Preview\FeedPreviewService;
use App\Export\Feed\Domain\Descriptor\FeedDescriptor;
use App\Export\Feed\Domain\Descriptor\InvalidDescriptorException;
use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Mapping\FeedFieldMapping;
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
 * Feed preview API (ADR-0023 §6.8, XMLF-P4-04) — backs the wizard "Podgląd"
 * step. `POST /api/feeds/preview` renders a draft (descriptor + mappings from
 * the request body, before the feed is saved); `GET /api/feeds/{id}/preview`
 * renders a saved feed. Both return the first N sample products as XML plus a
 * health report, in-memory (no FeedRun, no cache). Auto-registered into OpenAPI
 * by CustomRouteOpenApiFactory (ADR-0020).
 */
final class FeedPreviewController
{
    public function __construct(
        private readonly FeedProfileRepositoryInterface $feeds,
        private readonly FeedPreviewService $preview,
        private readonly FeedDescriptorGuard $guard,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
    ) {
    }

    #[Route(path: '/api/feeds/preview', name: 'pim_feeds_preview_draft', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all', anyOf: [
        'exports.view_all',
        'settings.integrations.manage',
    ])]
    public function draft(Request $request): JsonResponse
    {
        $this->resolveTenant();
        $payload = $this->decodeJson($request);

        $descriptorRaw = \is_array($payload['descriptor'] ?? null) ? $payload['descriptor'] : [];
        $this->assertDescriptor($descriptorRaw);
        $descriptor = FeedDescriptor::fromArray($descriptorRaw);

        $mappings = FeedFieldMapping::listFromArray(
            \is_array($payload['field_mappings'] ?? null)
                ? array_values(array_filter($payload['field_mappings'], \is_array(...)))
                : [],
        );

        $objectTypeId = $this->parseUuid($payload, 'object_type_id');
        $locale = $this->optionalString($payload, 'locale');
        $channel = $this->optionalString($payload, 'channel');
        $filter = \is_array($payload['filter'] ?? null) ? $payload['filter'] : null;

        $scope = new FeedProductScope($objectTypeId, $this->attributeCodes($mappings), $locale, $channel, $filter);
        $context = [
            'currency' => $this->optionalString($payload, 'currency') ?? '',
            'store_url' => $this->optionalString($payload, 'store_url') ?? '',
            'feed_name' => $this->optionalString($payload, 'name') ?? '',
        ];

        return new JsonResponse($this->preview->preview($descriptor, $mappings, $scope, $context, $this->limit($payload)));
    }

    #[Route(path: '/api/feeds/{id}/preview', name: 'pim_feeds_preview_saved', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all', anyOf: [
        'exports.view_all',
        'settings.integrations.manage',
    ])]
    public function saved(string $id, Request $request): JsonResponse
    {
        $this->resolveTenant();
        $feed = $this->loadOrFail($id);

        $descriptor = FeedDescriptor::fromArray($feed->getDescriptor());
        $mappings = FeedFieldMapping::listFromArray($feed->getFieldMappings());
        $scope = new FeedProductScope(
            $feed->getObjectTypeId(),
            $this->attributeCodes($mappings),
            $feed->getLocale(),
            $feed->getPublicationChannel(),
            $feed->getFilter(),
        );
        $delivery = $feed->getDelivery();
        $context = [
            'currency' => $feed->getCurrency() ?? '',
            'store_url' => \is_string($delivery['store_url'] ?? null) ? $delivery['store_url'] : '',
            'feed_name' => $feed->getName(),
        ];

        $limit = $request->query->getInt('limit', FeedPreviewService::DEFAULT_LIMIT);

        return new JsonResponse($this->preview->preview($descriptor, $mappings, $scope, $context, $limit));
    }

    /**
     * @param list<FeedFieldMapping> $mappings
     *
     * @return list<string>
     */
    private function attributeCodes(array $mappings): array
    {
        $codes = ['sku' => true]; // always needed for the health-report object_sku
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
    private function limit(array $payload): int
    {
        return \is_int($payload['limit'] ?? null) ? $payload['limit'] : FeedPreviewService::DEFAULT_LIMIT;
    }

    /**
     * @param array<mixed, mixed> $descriptor
     */
    private function assertDescriptor(array $descriptor): void
    {
        try {
            $this->guard->assertValid($descriptor);
        } catch (InvalidDescriptorException $error) {
            throw new BadRequestHttpException('Invalid feed descriptor: '.$error->getMessage(), $error);
        }
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
    private function optionalString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
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
