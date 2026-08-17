<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Domain\Entity\CategoryAttributeGroup;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\AttributeGroupRepositoryInterface;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\CategoryAttributeGroupRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use ValueError;

use const JSON_THROW_ON_ERROR;

/**
 * VIEW-04 (#408) — declare/undeclare/reorder/list AttributeGroup
 * declarations on a category for a specific target ObjectType.
 *
 *   - `POST   /api/categories/{id}/attribute_groups`
 *   - `GET    /api/categories/{id}/attribute_groups?targetObjectTypeKind=service`
 *   - `DELETE /api/categories/{id}/attribute_groups/{groupId}/{targetTypeId}`
 *   - `PATCH  /api/categories/{id}/attribute_groups/{groupId}/{targetTypeId}`
 *
 * The junction is the CategoryAttributeGroup entity (see ADR-012). Each
 * row pins (category, target_object_type, attribute_group, position).
 * `targetObjectTypeKind` on the public API maps to the built-in
 * ObjectType for the requesting tenant — admins do not pass UUIDs of
 * built-in types, the controller resolves them.
 *
 * Voter: `CatalogObjectVoter` on the parent category for write
 * operations + `READ` for the GET. Cross-tenant lookups return 404
 * because TenantFilter scopes the repositories first (the caller cannot
 * even fetch a foreign category by id).
 */
final class CategoryAttributeGroupController
{
    private const string UUID_REGEX = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly CatalogObjectRepositoryInterface $objects,
        private readonly ObjectTypeRepositoryInterface $objectTypes,
        private readonly AttributeGroupRepositoryInterface $attributeGroups,
        private readonly CategoryAttributeGroupRepositoryInterface $junctions,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route(
        '/api/categories/{id}/attribute_groups',
        name: 'pim_categories_attribute_groups_list',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'attribute_group', action: 'read', anyOf: [
        'attribute_group.read',
        'modeling.view',
        'products.view',
        'categories.view',
        'multimedia.view',
        'products.add',
        'categories.add_edit',
        'multimedia.add_edit_own',
        'multimedia.add_edit_any',
        'object.add',
    ])]
    public function list(string $id, Request $request): JsonResponse
    {
        $category = $this->fetchCategory($id);
        $targetType = $this->resolveTargetTypeByIdOrKind(
            $request->query->get('targetObjectTypeId'),
            $request->query->get('targetObjectTypeKind'),
        );

        $rows = $this->junctions->findByCategoryAndTarget($category, $targetType);

        $declaredGroups = [];
        foreach ($rows as $row) {
            $group = $row->getAttributeGroup();
            $declaredGroups[] = [
                'groupId' => $group->getId()->toRfc4122(),
                'position' => $row->getPosition(),
                'group' => [
                    'id' => $group->getId()->toRfc4122(),
                    'code' => $group->getCode(),
                    'label' => $group->getLabel(),
                    'description' => $group->getDescription(),
                    'icon' => $group->getIcon(),
                    'color' => $group->getColor(),
                    'is_system_group' => $group->isSystemGroup(),
                    'auto_attached' => $group->isAutoAttached(),
                ],
            ];
        }

        return new JsonResponse([
            'categoryId' => $category->getId()->toRfc4122(),
            'targetObjectType' => [
                'id' => $targetType->getId()->toRfc4122(),
                'code' => $targetType->getCode(),
                'kind' => $targetType->getKind()->value,
                'label' => $targetType->getLabel(),
            ],
            'declaredGroups' => $declaredGroups,
        ]);
    }

    #[Route(
        '/api/categories/{id}/attribute_groups',
        name: 'pim_categories_attribute_groups_declare',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'modeling.attribute_groups', action: 'add_edit')]
    public function declare(string $id, Request $request): JsonResponse
    {
        $category = $this->fetchCategory($id);

        $payload = $this->decodePayload($request);
        $groupId = $payload['groupId'] ?? null;
        $kindParam = $payload['targetObjectTypeKind'] ?? null;
        $idParam = $payload['targetObjectTypeId'] ?? null;

        if (!\is_string($groupId) || '' === $groupId) {
            throw new UnprocessableEntityHttpException('Field "groupId" is required.');
        }
        $targetType = $this->resolveTargetTypeByIdOrKind(
            \is_string($idParam) ? $idParam : null,
            \is_string($kindParam) ? $kindParam : null,
        );

        try {
            $groupUuid = Uuid::fromString($groupId);
        } catch (InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException(\sprintf('Field "groupId" is not a valid UUID: %s.', $groupId), $e);
        }

        $group = $this->attributeGroups->findById($groupUuid);
        if (null === $group) {
            throw new NotFoundHttpException(\sprintf('AttributeGroup "%s" was not found.', $groupId));
        }

        $existing = $this->junctions->findOne($category, $targetType, $group);
        if (null !== $existing) {
            return $this->renderJunction($existing, Response::HTTP_OK);
        }

        $position = ($this->junctions->maxPosition($category, $targetType) ?? -1) + 1;
        $junction = new CategoryAttributeGroup($category->getId(), $targetType, $group, $position);
        $this->junctions->save($junction);

        return $this->renderJunction($junction, Response::HTTP_CREATED);
    }

    #[Route(
        '/api/categories/{id}/attribute_groups/{groupId}/{targetTypeId}',
        name: 'pim_categories_attribute_groups_detach',
        requirements: ['id' => self::UUID_REGEX, 'groupId' => self::UUID_REGEX, 'targetTypeId' => self::UUID_REGEX],
        methods: ['DELETE'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'modeling.attribute_groups', action: 'add_edit')]
    public function detach(string $id, string $groupId, string $targetTypeId): JsonResponse
    {
        $category = $this->fetchCategory($id);

        $targetType = $this->objectTypes->findById(Uuid::fromString($targetTypeId));
        if (null === $targetType) {
            // Already gone → idempotent.
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        $group = $this->attributeGroups->findById(Uuid::fromString($groupId));
        if (null === $group) {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        $junction = $this->junctions->findOne($category, $targetType, $group);
        if (null === $junction) {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        $this->junctions->remove($junction);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route(
        '/api/categories/{id}/attribute_groups/{groupId}/{targetTypeId}',
        name: 'pim_categories_attribute_groups_reorder',
        requirements: ['id' => self::UUID_REGEX, 'groupId' => self::UUID_REGEX, 'targetTypeId' => self::UUID_REGEX],
        methods: ['PATCH'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'modeling.attribute_groups', action: 'add_edit')]
    public function reorder(string $id, string $groupId, string $targetTypeId, Request $request): JsonResponse
    {
        $category = $this->fetchCategory($id);

        $targetType = $this->objectTypes->findById(Uuid::fromString($targetTypeId));
        if (null === $targetType) {
            throw new NotFoundHttpException(\sprintf('ObjectType "%s" was not found.', $targetTypeId));
        }

        $group = $this->attributeGroups->findById(Uuid::fromString($groupId));
        if (null === $group) {
            throw new NotFoundHttpException(\sprintf('AttributeGroup "%s" was not found.', $groupId));
        }

        $junction = $this->junctions->findOne($category, $targetType, $group);
        if (null === $junction) {
            throw new NotFoundHttpException('CategoryAttributeGroup junction was not found.');
        }

        $payload = $this->decodePayload($request);
        $position = $payload['position'] ?? null;
        if (!\is_int($position) || $position < 0) {
            throw new UnprocessableEntityHttpException('Field "position" must be a non-negative integer.');
        }

        $junction->reorder($position);
        $this->junctions->save($junction);

        return $this->renderJunction($junction, Response::HTTP_OK);
    }

    private function fetchCategory(string $id): \App\Catalog\Domain\Entity\CatalogObject
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new BadRequestHttpException('No tenant context.');
        }

        $category = $this->objects->findById(Uuid::fromString($id));
        if (null === $category) {
            throw new NotFoundHttpException(\sprintf('Category "%s" was not found.', $id));
        }
        if (ObjectKind::Category !== $category->getKind()) {
            throw new UnprocessableEntityHttpException(\sprintf('Object "%s" is not a category.', $id));
        }

        return $category;
    }

    /**
     * ADR-015 — resolve the target ObjectType for a category's group
     * declaration. `targetObjectTypeId` (UUID) takes precedence and supports
     * custom-OT category trees; `targetObjectTypeKind` stays as the built-in
     * fallback for backward compatibility.
     */
    private function resolveTargetTypeByIdOrKind(
        ?string $idParam,
        ?string $kindParam,
    ): \App\Catalog\Domain\Entity\ObjectType {
        if (\is_string($idParam) && '' !== $idParam) {
            try {
                $uuid = Uuid::fromString($idParam);
            } catch (InvalidArgumentException $e) {
                throw new BadRequestHttpException(\sprintf('Invalid targetObjectTypeId "%s".', $idParam), $e);
            }
            $type = $this->objectTypes->findById($uuid);
            if (null === $type) {
                throw new NotFoundHttpException(\sprintf('ObjectType "%s" was not found.', $idParam));
            }

            return $type;
        }

        return $this->resolveTargetType($kindParam);
    }

    private function resolveTargetType(?string $kindParam): \App\Catalog\Domain\Entity\ObjectType
    {
        if (!\is_string($kindParam) || '' === $kindParam) {
            throw new BadRequestHttpException('Query parameter "targetObjectTypeKind" is required.');
        }
        try {
            $kind = ObjectKind::from($kindParam);
        } catch (ValueError $e) {
            throw new BadRequestHttpException(\sprintf('Unknown targetObjectTypeKind "%s".', $kindParam), $e);
        }

        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new BadRequestHttpException('No tenant context.');
        }

        $type = $this->objectTypes->findBuiltInByKind($kind, $tenant);
        if (null === $type) {
            throw new NotFoundHttpException(\sprintf('Built-in ObjectType for kind "%s" not found.', $kindParam));
        }

        return $type;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(Request $request): array
    {
        $body = $request->getContent();
        if ('' === $body) {
            return [];
        }
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new BadRequestHttpException('Request body is not valid JSON.', $e);
        }
        if (!\is_array($decoded)) {
            throw new BadRequestHttpException('Request body must be a JSON object.');
        }
        $normalized = [];
        foreach ($decoded as $key => $value) {
            if (\is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function renderJunction(CategoryAttributeGroup $junction, int $status): JsonResponse
    {
        $group = $junction->getAttributeGroup();
        $type = $junction->getTargetObjectType();

        return new JsonResponse([
            'categoryId' => $junction->getCategoryObjectId()->toRfc4122(),
            'targetObjectType' => [
                'id' => $type->getId()->toRfc4122(),
                'code' => $type->getCode(),
                'kind' => $type->getKind()->value,
                'label' => $type->getLabel(),
            ],
            'group' => [
                'id' => $group->getId()->toRfc4122(),
                'code' => $group->getCode(),
                'label' => $group->getLabel(),
                'description' => $group->getDescription(),
                'icon' => $group->getIcon(),
                'color' => $group->getColor(),
                'is_system_group' => $group->isSystemGroup(),
                'auto_attached' => $group->isAutoAttached(),
            ],
            'position' => $junction->getPosition(),
        ], $status);
    }
}
