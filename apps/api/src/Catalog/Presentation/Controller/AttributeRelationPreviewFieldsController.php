<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * MODR-08 (#930) follow-up — dedicated read endpoint for the
 * `relation_preview_fields` column on `attributes`. ApiPlatform 4's
 * PropertyInfo extractor refused to surface the new JSONB array
 * property through the regular `GET /api/attributes/{id}` JSON-LD
 * response even after a full cache rebuild (other identical-shape
 * JSONB array fields on the same entity, e.g.
 * `relation_target_object_type_ids`, do surface — the discrepancy
 * appears to be an AP4 metadata-discovery quirk on properties added
 * after the resource's first compile). Rather than fighting AP4
 * metadata, we expose a small dedicated endpoint the relation
 * configurator uses to read the persisted list back.
 *
 * `PATCH /api/attributes/{id}` already accepts `relationPreviewFields`
 * via {@see \App\Catalog\Infrastructure\ApiPlatform\Resource\AttributePatchInput}.
 */
final class AttributeRelationPreviewFieldsController
{
    private const string UUID_REGEX = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly AttributeRepositoryInterface $attributes,
    ) {
    }

    #[Route(
        '/api/attributes/{id}/relation_preview_fields',
        name: 'pim_attributes_relation_preview_fields',
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

        return new JsonResponse([
            'attributeId' => $attribute->getId()->toRfc4122(),
            'relationPreviewFields' => $attribute->getRelationPreviewFields(),
        ]);
    }
}
