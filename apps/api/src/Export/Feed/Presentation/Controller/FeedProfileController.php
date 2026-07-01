<?php

declare(strict_types=1);

namespace App\Export\Feed\Presentation\Controller;

use App\Export\Feed\Application\Descriptor\FeedDescriptorGuard;
use App\Export\Feed\Domain\Descriptor\InvalidDescriptorException;
use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Enum\FeedTemplateKind;
use App\Export\Feed\Domain\Enum\FeedValidationPolicy;
use App\Export\Feed\Domain\Repository\FeedProfileRepositoryInterface;
use App\Export\Feed\Domain\Template\FeedTemplateCatalog;
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
 * CRUD for feed profiles (ADR-0023, XMLF-P1-03). Tenant-scoped custom routes
 * (CQRS, ADR-0012) — the descriptor is validated on write by the persist guard
 * (XMLF-P1-04); predefs seed their descriptor from the template catalog
 * (XMLF-P2-02). Auto-registered into OpenAPI by CustomRouteOpenApiFactory
 * (ADR-0020).
 */
final class FeedProfileController
{
    public function __construct(
        private readonly FeedProfileRepositoryInterface $feeds,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
        private readonly FeedDescriptorGuard $guard,
        private readonly FeedTemplateCatalog $catalog,
    ) {
    }

    #[Route(path: '/api/feeds', name: 'pim_feeds_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all')]
    public function list(): JsonResponse
    {
        [$tenant] = $this->resolveTenant();

        return new JsonResponse([
            'items' => array_map($this->serialize(...), $this->feeds->findByTenant($tenant)),
        ]);
    }

    #[Route(path: '/api/feeds', name: 'pim_feeds_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'integration', action: 'admin')]
    public function create(Request $request): JsonResponse
    {
        [$tenant] = $this->resolveTenant();
        $payload = $this->decodeJson($request);

        $kind = $this->parseKind($payload);
        $code = $this->parseString($payload, 'code');
        $name = $this->parseString($payload, 'name');
        $objectTypeId = $this->parseUuid($payload, 'object_type_id');

        [$descriptor, $mappings] = $this->resolveDescriptor($payload, $kind);
        $this->assertDescriptor($descriptor);

        if (null !== $this->feeds->findByTenantAndCode($tenant, $code)) {
            throw new BadRequestHttpException(sprintf('A feed with code "%s" already exists.', $code));
        }

        $feed = new FeedProfile(
            code: $code,
            name: $name,
            templateKind: $kind,
            objectTypeId: $objectTypeId,
            descriptor: $descriptor,
            fieldMappings: $mappings,
            locale: $this->parseOptionalString($payload, 'locale'),
            currency: $this->parseOptionalString($payload, 'currency'),
            validationPolicy: $this->parsePolicy($payload),
        );
        $feed->assignTenant($tenant);
        $this->feeds->save($feed);

        return new JsonResponse($this->serialize($feed), Response::HTTP_CREATED);
    }

    #[Route(path: '/api/feeds/{id}', name: 'pim_feeds_get', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all')]
    public function get(string $id): JsonResponse
    {
        return new JsonResponse($this->serialize($this->loadOrFail($id)));
    }

    #[Route(path: '/api/feeds/{id}', name: 'pim_feeds_patch', methods: ['PATCH'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'integration', action: 'admin')]
    public function patch(string $id, Request $request): JsonResponse
    {
        $feed = $this->loadOrFail($id);
        $payload = $this->decodeJson($request);

        if (\array_key_exists('name', $payload)) {
            $feed->rename($this->parseString($payload, 'name'));
        }
        if (\array_key_exists('descriptor', $payload)) {
            $descriptor = \is_array($payload['descriptor']) ? $payload['descriptor'] : [];
            $this->assertDescriptor($descriptor);
            $feed->updateDescriptor($descriptor);
        }
        if (\array_key_exists('field_mappings', $payload) && \is_array($payload['field_mappings'])) {
            $feed->updateFieldMappings(array_values(array_filter($payload['field_mappings'], \is_array(...))));
        }
        if (\array_key_exists('filter', $payload)) {
            $feed->updateFilter(\is_array($payload['filter']) ? $payload['filter'] : null);
        }
        if (\array_key_exists('schedule_cron', $payload)) {
            $feed->setScheduleCron($this->parseOptionalString($payload, 'schedule_cron'));
        }
        if (\array_key_exists('validation_policy', $payload)) {
            $feed->setValidationPolicy($this->parsePolicy($payload));
        }

        $this->feeds->save($feed);

        return new JsonResponse($this->serialize($feed));
    }

    #[Route(path: '/api/feeds/{id}', name: 'pim_feeds_delete', methods: ['DELETE'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'integration', action: 'admin')]
    public function delete(string $id): Response
    {
        $this->feeds->remove($this->loadOrFail($id));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route(path: '/api/feeds/{id}/pause', name: 'pim_feeds_pause', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'integration', action: 'admin')]
    public function pause(string $id): JsonResponse
    {
        $feed = $this->loadOrFail($id);
        $feed->pause();
        $this->feeds->save($feed);

        return new JsonResponse($this->serialize($feed));
    }

    #[Route(path: '/api/feeds/{id}/resume', name: 'pim_feeds_resume', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'integration', action: 'admin')]
    public function resume(string $id): JsonResponse
    {
        $feed = $this->loadOrFail($id);
        $feed->resume();
        $this->feeds->save($feed);

        return new JsonResponse($this->serialize($feed));
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

    private function loadOrFail(string $id): FeedProfile
    {
        $this->resolveTenant();
        $feed = $this->feeds->findById(Uuid::fromString($id));
        if (null === $feed) {
            // Tenant isolation via RLS/TenantFilter; a miss is a 404 either way.
            throw new NotFoundHttpException(sprintf('Feed "%s" was not found.', $id));
        }

        return $feed;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{0: array<mixed, mixed>, 1: list<array<mixed, mixed>>}
     */
    private function resolveDescriptor(array $payload, FeedTemplateKind $kind): array
    {
        if (\array_key_exists('descriptor', $payload)) {
            $descriptor = \is_array($payload['descriptor']) ? $payload['descriptor'] : [];
            $mappings = \is_array($payload['field_mappings'] ?? null)
                ? array_values(array_filter($payload['field_mappings'], \is_array(...)))
                : [];

            return [$descriptor, $mappings];
        }

        // No explicit descriptor — seed from the built-in template.
        $template = $this->catalog->get($kind);

        return [$template->descriptor, $template->defaultMappings];
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

    /**
     * @param array<string, mixed> $payload
     */
    private function parseKind(array $payload): FeedTemplateKind
    {
        $value = $payload['template_kind'] ?? null;
        $kind = \is_string($value) ? FeedTemplateKind::tryFrom($value) : null;
        if (null === $kind) {
            throw new BadRequestHttpException('template_kind is required (google_shopping|ceneo|meta|custom).');
        }

        return $kind;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function parsePolicy(array $payload): FeedValidationPolicy
    {
        $value = $payload['validation_policy'] ?? FeedValidationPolicy::SkipInvalid->value;

        return (\is_string($value) ? FeedValidationPolicy::tryFrom($value) : null) ?? FeedValidationPolicy::SkipInvalid;
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
    private function serialize(FeedProfile $feed): array
    {
        $delivery = $feed->getDelivery();
        // Never expose secrets in the API surface.
        if (\is_array($delivery['auth'] ?? null)) {
            unset($delivery['auth']['encrypted_password'], $delivery['auth']['password']);
        }

        return [
            'id' => $feed->getId()->toRfc4122(),
            'code' => $feed->getCode(),
            'name' => $feed->getName(),
            'template_kind' => $feed->getTemplateKind()->value,
            'object_type_id' => $feed->getObjectTypeId()->toRfc4122(),
            'status' => $feed->getStatus()->value,
            'validation_policy' => $feed->getValidationPolicy()->value,
            'locale' => $feed->getLocale(),
            'currency' => $feed->getCurrency(),
            'publication_channel' => $feed->getPublicationChannel(),
            'schedule_cron' => $feed->getScheduleCron(),
            'descriptor' => $feed->getDescriptor(),
            'field_mappings' => $feed->getFieldMappings(),
            'filter' => $feed->getFilter(),
            'delivery' => $delivery,
            'cached_item_count' => $feed->getCachedItemCount(),
            'cached_at' => $feed->getCachedAt()?->format(DateTimeInterface::ATOM),
            'last_run_id' => $feed->getLastRunId()?->toRfc4122(),
            'created_at' => $feed->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updated_at' => $feed->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ];
    }
}
