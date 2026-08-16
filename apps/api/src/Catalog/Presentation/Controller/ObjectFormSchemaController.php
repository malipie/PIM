<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Application\Query\GetObjectFormSchema\GetObjectFormSchemaHandler;
use App\Catalog\Application\Query\GetObjectFormSchema\GetObjectFormSchemaQuery;
use App\Identity\Contracts\Attribute\RequiresPermission;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * UI-08.4 (#259) — `GET /api/objects/{id}/form-schema`.
 *
 * Returns the effective form schema for a CatalogObject: the ObjectType
 * header + the deduplicated, ordered list of AttributeGroups (with their
 * attributes inlined). Used by:
 *   - the admin form renderer (#56) to lay out the dynamic form,
 *   - `EffectiveAttributesPreview` widget (#UI-08.13) for "see what the
 *     user will see" preview,
 *   - the migration impact analyzer (#UI-08.12).
 *
 * Cross-tenant queries are blocked at the repository layer
 * (TenantFilter) — the response shape is identical for "tenant
 * mismatch" and "object not found" (404 in both cases).
 */
final class ObjectFormSchemaController
{
    private const string UUID_REGEX = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly GetObjectFormSchemaHandler $handler,
    ) {
    }

    #[Route(
        '/api/objects/{id}/form-schema',
        name: 'pim_objects_form_schema',
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
    public function __invoke(string $id): JsonResponse
    {
        $schema = ($this->handler)(new GetObjectFormSchemaQuery(Uuid::fromString($id)));
        if (null === $schema) {
            throw new NotFoundHttpException(\sprintf('Object "%s" was not found.', $id));
        }

        return new JsonResponse($schema->toArray());
    }
}
