<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Application\Query\Usage\UsageQueryService;
use App\Catalog\Domain\Repository\AttributeGroupRepositoryInterface;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * UI-08.7 (#262) — `where-used` endpoints for Attribute, AttributeGroup,
 * and ObjectType. Each endpoint returns the surface the admin UI uses
 * to gate destructive operations:
 *
 *   GET /api/attributes/{id}/usage         (#UI-08.11 detail panel)
 *   GET /api/attribute_groups/{id}/usage   (#UI-08.13 delete-protect modal)
 *   GET /api/object_types/{id}/usage       (#UI-08.10 detail panel)
 *
 * Returns 404 when the parent row is missing or in another tenant
 * (TenantFilter applied via repository find).
 */
final class UsageController
{
    private const string UUID_REGEX = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly AttributeRepositoryInterface $attributes,
        private readonly AttributeGroupRepositoryInterface $attributeGroups,
        private readonly ObjectTypeRepositoryInterface $objectTypes,
        private readonly UsageQueryService $usage,
    ) {
    }

    #[Route(
        '/api/attributes/{id}/usage',
        name: 'pim_attributes_usage',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    // #2881 — schema metadata reads follow reading the data they describe,
    // the #2838 pattern: a Catalog Manager needs the attribute's usage to
    // render a product form without holding modeling.view.
    #[RequiresPermission(module: 'attribute', action: 'read', anyOf: [
        'attribute.read',
        'modeling.view',
        'products.view',
        'categories.view',
        'multimedia.view',
    ])]
    public function attribute(string $id): JsonResponse
    {
        $attribute = $this->attributes->findById(Uuid::fromString($id));
        if (null === $attribute) {
            throw new NotFoundHttpException(\sprintf('Attribute "%s" was not found.', $id));
        }

        return new JsonResponse($this->usage->forAttribute($attribute));
    }

    #[Route(
        '/api/attribute_groups/{id}/usage',
        name: 'pim_attribute_groups_usage',
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
    ])]
    public function attributeGroup(string $id): JsonResponse
    {
        $group = $this->attributeGroups->findById(Uuid::fromString($id));
        if (null === $group) {
            throw new NotFoundHttpException(\sprintf('AttributeGroup "%s" was not found.', $id));
        }

        return new JsonResponse($this->usage->forAttributeGroup($group));
    }

    #[Route(
        '/api/object_types/{id}/usage',
        name: 'pim_object_types_usage',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'object_type', action: 'read', anyOf: [
        'object_type.read',
        'modeling.view',
        'products.view',
        'categories.view',
        'multimedia.view',
    ])]
    public function objectType(string $id): JsonResponse
    {
        $type = $this->objectTypes->findById(Uuid::fromString($id));
        if (null === $type) {
            throw new NotFoundHttpException(\sprintf('ObjectType "%s" was not found.', $id));
        }

        return new JsonResponse($this->usage->forObjectType($type));
    }
}
