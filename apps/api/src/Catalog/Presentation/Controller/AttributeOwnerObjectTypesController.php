<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeAttributeRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * #979 — list ObjectTypes that own a given attribute via the
 * `object_type_attributes` junction. Powers the "Typy obiektów" toggle-chip
 * card in the Modeling → Attribute detail view: the operator can see and
 * change ObjectType ownership directly from the attribute page instead of
 * navigating into each ObjectType edit screen.
 *
 * Mutations stay on the existing endpoints
 * ({@see AttachObjectTypeAttributeController} `POST`/`DELETE`
 * `/api/object_types/{id}/attributes/{attributeId}`) — this controller is
 * read-only and intentionally narrow.
 */
final class AttributeOwnerObjectTypesController
{
    private const string UUID_REGEX = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly AttributeRepositoryInterface $attributes,
        private readonly ObjectTypeAttributeRepositoryInterface $junctions,
    ) {
    }

    #[Route(
        '/api/attributes/{id}/owner_object_types',
        name: 'pim_attributes_owner_object_types',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'attribute', action: 'read', anyOf: [
        'attribute.read',
        'modeling.view',
        'products.view',
        'categories.view',
        'multimedia.view',
    ])]
    public function __invoke(string $id): JsonResponse
    {
        $attribute = $this->attributes->findById(Uuid::fromString($id));
        if (null === $attribute) {
            throw new NotFoundHttpException(\sprintf('Attribute "%s" was not found.', $id));
        }

        $ids = [];
        foreach ($this->junctions->findByAttribute($attribute) as $junction) {
            $ids[] = $junction->getObjectType()->getId()->toRfc4122();
        }

        return new JsonResponse([
            'attributeId' => $attribute->getId()->toRfc4122(),
            'objectTypeIds' => $ids,
        ]);
    }
}
