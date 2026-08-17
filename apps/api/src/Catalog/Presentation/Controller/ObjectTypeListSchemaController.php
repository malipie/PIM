<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Application\Query\GetObjectTypeListSchema\GetObjectTypeListSchemaHandler;
use App\Catalog\Application\Query\GetObjectTypeListSchema\GetObjectTypeListSchemaQuery;
use App\Identity\Contracts\Attribute\RequiresPermission;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * ULV-03 (#984) — `GET /api/object-types/{id}/list-schema`.
 *
 * Returns the universal list schema for an ObjectType:
 *   - `objectType` header with capability flags (drives conditional
 *     features in `ObjectListView`: variants column when `has_variants`,
 *     category sidebar when `is_categorizable`),
 *   - `columns` — fixed system columns (code, status, completeness,
 *     updatedAt) followed by attribute columns flagged
 *     `object_type_attributes.show_in_list=true`, ordered by
 *     `list_position`,
 *   - `filterableAttributes` — codes the universal list endpoint
 *     (`GET /api/objects?objectType=...&filter[...]=...`) accepts.
 *     Anything not in this list is rejected with 400/Problem Details.
 *   - `searchableAttributes` — codes the universal search (`?q=`) scores
 *     against. MVP heuristic: text-typed filterable attributes only.
 *
 * Cross-tenant queries are blocked at the repository layer (TenantFilter)
 * — the response shape is identical for "tenant mismatch" and "object
 * type not found" (404 in both cases).
 */
final class ObjectTypeListSchemaController
{
    private const string UUID_REGEX = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly GetObjectTypeListSchemaHandler $handler,
    ) {
    }

    #[Route(
        '/api/object_types/{id}/list-schema',
        name: 'pim_object_types_list_schema',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    // #2838 — the list schema is what the product/category/asset lists need
    // to know which columns to draw. Requiring `object_type.read` (or
    // `modeling.view`) alone made a Catalog Manager's own product list 403
    // on its type definition, which the UI reported as "run the catalog
    // seeder". Reading schema metadata follows reading the data.
    // #2881 — the create codes join the read codes. A role granted only
    // "add a product" could POST an object it had no way to compose: the
    // form reads the schema first, and the schema asked for a *view* code
    // it did not hold. Reading the schema follows reading OR creating the
    // data it describes; #2838 only covered the reading half.
    #[RequiresPermission(module: 'object_type', action: 'read', anyOf: [
        'object_type.read',
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
    public function __invoke(string $id, Request $request): JsonResponse
    {
        // GRID-P3-01 (#2392) — `?full=1` returns the complete attribute
        // catalogue (all attached attributes with `default` + `group`),
        // powering the column manager. RBAC filtering is identical in
        // both modes; without the flag the response is unchanged.
        $schema = ($this->handler)(new GetObjectTypeListSchemaQuery(
            Uuid::fromString($id),
            full: $request->query->getBoolean('full'),
        ));
        if (null === $schema) {
            throw new NotFoundHttpException(\sprintf('ObjectType "%s" was not found.', $id));
        }

        return new JsonResponse($schema->toArray());
    }
}
