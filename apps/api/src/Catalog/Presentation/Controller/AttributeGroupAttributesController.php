<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Domain\Repository\AttributeGroupRepositoryInterface;
use App\Catalog\Domain\Service\EffectiveAttributeGroupResolver;
use App\Identity\Contracts\Attribute\RequiresPermission;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * UI-08.13 (#268) — `GET /api/attribute_groups/{id}/attributes`.
 *
 * Returns the list of attributes attached to an AttributeGroup with the
 * junction-side metadata the admin UI needs to render the membership
 * editor (position, is_required_in_group, visible_when). Reuses
 * `EffectiveAttributeGroupResolver::loadGroupAttributes()` which already
 * loads the rows in (position, code) order.
 *
 * Read-only endpoint — write paths to attach / detach / reorder live in
 * `AttributeGroupAttributeController` (UI-08.8 PATCH endpoint) and the
 * future `AttachAttributeToGroup` command (post-MVP).
 */
final class AttributeGroupAttributesController
{
    private const string UUID_REGEX = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly AttributeGroupRepositoryInterface $groups,
        private readonly EffectiveAttributeGroupResolver $resolver,
    ) {
    }

    #[Route(
        '/api/attribute_groups/{id}/attributes',
        name: 'pim_attribute_groups_attributes',
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
    public function __invoke(string $id): JsonResponse
    {
        $group = $this->groups->findById(Uuid::fromString($id));
        if (null === $group) {
            throw new NotFoundHttpException(\sprintf('AttributeGroup "%s" was not found.', $id));
        }

        $byGroup = $this->resolver->loadGroupAttributes([$group]);
        $junctions = $byGroup[$group->getId()->toRfc4122()] ?? [];

        $rows = [];
        foreach ($junctions as $junction) {
            $attribute = $junction->getAttribute();
            $rows[] = [
                'attribute' => [
                    'id' => $attribute->getId()->toRfc4122(),
                    'code' => $attribute->getCode(),
                    'type' => $attribute->getType()->value,
                    'label' => $attribute->getLabel(),
                    'is_system' => $attribute->isSystem(),
                ],
                'position' => $junction->getPosition(),
                'is_required_in_group' => $junction->isRequiredInGroup(),
                'visible_when' => $junction->getVisibleWhen(),
            ];
        }

        return new JsonResponse([
            'attributeGroupId' => $group->getId()->toRfc4122(),
            'members' => $rows,
        ]);
    }
}
