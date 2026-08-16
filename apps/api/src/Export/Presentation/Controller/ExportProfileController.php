<?php

declare(strict_types=1);

namespace App\Export\Presentation\Controller;

use App\Export\Domain\Entity\ExportProfile;
use App\Export\Domain\Enum\ExportEntityType;
use App\Export\Domain\Enum\ExportTargetScope;
use App\Export\Domain\Repository\ExportProfileRepositoryInterface;
use App\Export\Presentation\Support\ExportEntityTypeResolver;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use App\Shared\Application\UserIdentityAware;
use DateTimeInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * EXP-07 (#586) — Saved Export Profiles CRUD endpoints.
 *
 * Per-user scope (PRD §3.3 punkt 2, §5.1) — MVP shows only the caller's
 * profiles. Cross-user sharing is a Faza 1 follow-up tied to the team
 * model.
 *
 * Endpoints:
 *   - GET    /api/exports/profiles
 *   - POST   /api/exports/profiles
 *   - GET    /api/exports/profiles/{id}
 *   - PATCH  /api/exports/profiles/{id}
 *   - DELETE /api/exports/profiles/{id}
 *
 * Run-now (POST /{id}/run) lives separately under the Sessions controller
 * (EXP-08) so profile management stays a pure CRUD surface.
 *
 * Cross-user reads return 404 (information hiding, same convention as
 * the SmartFilterPreset controller VIEW-09).
 */
final class ExportProfileController
{
    private const string UUID_REGEX = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
    private const int MAX_NAME_LEN = 255;
    private const int MIN_NAME_LEN = 1;

    public function __construct(
        private readonly ExportProfileRepositoryInterface $profiles,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
        private readonly ExportEntityTypeResolver $entityTypeResolver,
    ) {
    }

    #[Route(
        path: '/api/exports/profiles',
        name: 'pim_export_profiles_list',
        methods: ['GET'],
    )]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all')]
    public function list(Request $request): JsonResponse
    {
        [$tenant, $userId] = $this->resolveTenantAndUser();
        $rows = $this->profiles->findByTenantAndUser($tenant, $userId);

        return new JsonResponse([
            'items' => array_map([$this, 'serialize'], $rows),
            'total' => \count($rows),
        ]);
    }

    #[Route(
        path: '/api/exports/profiles',
        name: 'pim_export_profiles_create',
        methods: ['POST'],
    )]
    #[IsGranted('ROLE_USER')]
    // #2881 — the reads on this controller already ask for exports.view_all;
    // the writes asked for legacy integration.admin. Whoever may run an
    // export may configure one, so they follow `exports.run`.
    #[RequiresPermission(module: 'integration', action: 'admin', anyOf: [
        'integration.admin',
        'exports.run',
    ])]
    public function create(Request $request): JsonResponse
    {
        [$tenant, $userId] = $this->resolveTenantAndUser();
        $payload = $this->decodeJson($request);
        $name = $this->parseName($payload);
        $description = $this->parseDescription($payload);
        $selection = $this->entityTypeResolver->resolve($payload);
        $config = $this->parseConfig($payload);
        $this->assertConfigScope($selection->entityType, $config);

        $existing = $this->profiles->findByTenantUserAndName($tenant, $userId, $name);
        if (null !== $existing) {
            throw new ConflictHttpException(sprintf('Export profile with name "%s" already exists for this user.', $name));
        }

        $profile = new ExportProfile(
            userId: $userId,
            name: $name,
            config: $config,
            description: $description,
            entityType: $selection->entityType,
            objectTypeId: $selection->objectTypeId,
        );
        $profile->assignTenant($tenant);
        $this->profiles->save($profile);

        return new JsonResponse($this->serialize($profile), Response::HTTP_CREATED);
    }

    #[Route(
        path: '/api/exports/profiles/{id}',
        name: 'pim_export_profiles_get',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['GET'],
    )]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all')]
    public function get(string $id): JsonResponse
    {
        $profile = $this->loadOwnedOrFail($id);

        return new JsonResponse($this->serialize($profile));
    }

    #[Route(
        path: '/api/exports/profiles/{id}',
        name: 'pim_export_profiles_patch',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['PATCH'],
    )]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'integration', action: 'admin', anyOf: [
        'integration.admin',
        'exports.run',
    ])]
    public function patch(string $id, Request $request): JsonResponse
    {
        $profile = $this->loadOwnedOrFail($id);
        $payload = $this->decodeJson($request);

        if (\array_key_exists('name', $payload)) {
            $name = $this->parseName($payload);
            if ($name !== $profile->getName()) {
                $tenant = $profile->getTenant();
                if (null !== $tenant) {
                    $existing = $this->profiles->findByTenantUserAndName($tenant, $profile->getUserId(), $name);
                    if (null !== $existing && !$existing->getId()->equals($profile->getId())) {
                        throw new ConflictHttpException(sprintf('Export profile with name "%s" already exists for this user.', $name));
                    }
                }
                $profile->rename($name);
            }
        }

        if (\array_key_exists('description', $payload)) {
            $profile->setDescription($this->parseDescription($payload));
        }

        // Re-classify when either entity_type or object_type_id is supplied;
        // merge with the current values so a partial PATCH stays valid.
        if (\array_key_exists('entity_type', $payload) || \array_key_exists('object_type_id', $payload)) {
            $merged = $payload + [
                'entity_type' => $profile->getEntityType()->value,
                'object_type_id' => $profile->getObjectTypeId()?->toRfc4122(),
            ];
            $selection = $this->entityTypeResolver->resolve($merged);
            $profile->reclassify($selection->entityType, $selection->objectTypeId);
        }

        if (\array_key_exists('config', $payload)) {
            $config = $this->parseConfig($payload);
            $this->assertConfigScope($profile->getEntityType(), $config);
            $profile->updateConfig($config);
        } else {
            // entity_type may have changed against an existing config.
            $this->assertConfigScope($profile->getEntityType(), $profile->getConfig());
        }

        $this->profiles->save($profile);

        return new JsonResponse($this->serialize($profile));
    }

    #[Route(
        path: '/api/exports/profiles/{id}',
        name: 'pim_export_profiles_delete',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['DELETE'],
    )]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'integration', action: 'admin', anyOf: [
        'integration.admin',
        'exports.run',
    ])]
    public function delete(string $id): Response
    {
        $profile = $this->loadOwnedOrFail($id);
        $this->profiles->remove($profile);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array{0: \App\Shared\Domain\Tenant, 1: Uuid}
     */
    private function resolveTenantAndUser(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof UserIdentityAware) {
            throw new AccessDeniedHttpException('Authenticated user identity required.');
        }
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new AccessDeniedHttpException('Tenant context required.');
        }

        return [$tenant, $user->getId()];
    }

    private function loadOwnedOrFail(string $id): ExportProfile
    {
        $profile = $this->profiles->findById(Uuid::fromString($id));
        if (null === $profile) {
            throw new NotFoundHttpException(sprintf('Export profile "%s" was not found.', $id));
        }
        [, $userId] = $this->resolveTenantAndUser();
        if (!$profile->isOwnedBy($userId)) {
            // Cross-user — information-hide as 404 (matches SmartFilterPreset).
            throw new NotFoundHttpException(sprintf('Export profile "%s" was not found.', $id));
        }

        return $profile;
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
     * @param array<string, mixed> $payload
     */
    private function parseName(array $payload): string
    {
        $value = $payload['name'] ?? null;
        if (!\is_string($value)) {
            throw new BadRequestHttpException('name is required (string).');
        }
        $name = trim($value);
        $length = mb_strlen($name);
        if ($length < self::MIN_NAME_LEN || $length > self::MAX_NAME_LEN) {
            throw new BadRequestHttpException(sprintf(
                'name length must be between %d and %d.',
                self::MIN_NAME_LEN,
                self::MAX_NAME_LEN,
            ));
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function parseDescription(array $payload): ?string
    {
        $value = $payload['description'] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw new BadRequestHttpException('description must be a string or null.');
        }
        $description = trim($value);

        return '' === $description ? null : $description;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function parseConfig(array $payload): array
    {
        $value = $payload['config'] ?? null;
        if (!\is_array($value)) {
            throw new BadRequestHttpException('config is required (JSON object).');
        }
        $config = [];
        foreach ($value as $k => $v) {
            if (!\is_string($k)) {
                throw new BadRequestHttpException('config must be a JSON object (string keys).');
            }
            $config[$k] = $v;
        }
        // Minimal shape check — selected_columns is mandatory per PRD §5.3.
        $cols = $config['selected_columns'] ?? null;
        if (!\is_array($cols) || [] === $cols) {
            throw new BadRequestHttpException('config.selected_columns must be a non-empty array of column keys.');
        }
        foreach ($cols as $col) {
            if (!\is_string($col) || '' === $col) {
                throw new BadRequestHttpException('config.selected_columns entries must be non-empty strings.');
            }
        }

        return $config;
    }

    /**
     * Structural entity types always export the full set — a profile must not
     * pin them to a narrower `default_target_scope` (EXR-04 / spec §2 D2).
     *
     * @param array<string, mixed> $config
     */
    private function assertConfigScope(ExportEntityType $entityType, array $config): void
    {
        if ($entityType->supportsScopeAndFilter()) {
            return;
        }
        $scope = $config['default_target_scope'] ?? null;
        if (null === $scope) {
            return;
        }
        if (!\is_string($scope) || ExportTargetScope::All !== ExportTargetScope::tryFrom($scope)) {
            throw new UnprocessableEntityHttpException(sprintf(
                'entity_type=%s exports the full structure — config.default_target_scope must be "all" or omitted.',
                $entityType->value,
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ExportProfile $profile): array
    {
        return [
            'id' => $profile->getId()->toRfc4122(),
            'name' => $profile->getName(),
            'description' => $profile->getDescription(),
            'entity_type' => $profile->getEntityType()->value,
            'object_type_id' => $profile->getObjectTypeId()?->toRfc4122(),
            'config' => $profile->getConfig(),
            'last_run_at' => $profile->getLastRunAt()?->format(DateTimeInterface::ATOM),
            'run_count' => $profile->getRunCount(),
            'created_at' => $profile->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updated_at' => $profile->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ];
    }
}
