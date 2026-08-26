<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Application\Query\Usage\UsageQueryService;
use App\Identity\Contracts\Attribute\RequiresPermission;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * #3034 — batch `where-used` reads for the modeling list pages.
 *
 *   GET /api/modeling/usage/attributes?ids=<csv>
 *   GET /api/modeling/usage/attribute-groups?ids=<csv>
 *   GET /api/modeling/usage/object-types?ids=<csv>
 *
 * Each returns `{ "<uuid>": <same payload as GET /api/{resource}/{id}/usage> }`.
 * The per-item endpoints in {@see UsageController} stay exactly as they were —
 * `<WhereUsedList>` and the delete-protection modals still use them.
 *
 * **Why a separate `/api/modeling/…` path rather than `/api/attributes/usage`**:
 * `/api/attributes/{id}` also accepts attribute *codes* (see
 * `/api/attributes/vat_rate/options`), so a literal `usage` segment there would
 * be ambiguous with an attribute whose code happens to be "usage".
 *
 * **Why three routes rather than one `?resource=` switch**: each resource needs
 * its own `#[RequiresPermission]` grant set, and those are static attributes.
 * Dispatching on a query parameter would push the RBAC decision into the method
 * body, out of reach of the static gates that audit permission coverage.
 *
 * Ids naming rows outside the caller's tenant are dropped rather than answered
 * with zeros — see {@see UsageQueryService::existingIds()} for why that matters
 * to the shared (non-tenant-namespaced) cache pool.
 */
final readonly class ModelingUsageController
{
    public function __construct(
        private UsageQueryService $usage,
    ) {
    }

    #[Route(
        '/api/modeling/usage/attributes',
        name: 'pim_modeling_usage_attributes',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    // Grant set copied verbatim from UsageController::attribute() — a batch
    // read must not be a cheaper way to reach data the per-item route gates.
    #[RequiresPermission(module: 'attribute', action: 'read', anyOf: [
        'attribute.read',
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
    public function attributes(Request $request): JsonResponse
    {
        return self::respond($this->usage->forAttributes(self::parseIds($request)));
    }

    #[Route(
        '/api/modeling/usage/attribute-groups',
        name: 'pim_modeling_usage_attribute_groups',
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
    public function attributeGroups(Request $request): JsonResponse
    {
        return self::respond($this->usage->forAttributeGroups(self::parseIds($request)));
    }

    #[Route(
        '/api/modeling/usage/object-types',
        name: 'pim_modeling_usage_object_types',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
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
    public function objectTypes(Request $request): JsonResponse
    {
        return self::respond($this->usage->forObjectTypes(self::parseIds($request)));
    }

    /**
     * The payload is a map keyed by id. PHP encodes an empty array as `[]`,
     * which would flip the response type between object and array depending
     * on whether anything matched — so an empty result is forced to `{}`.
     *
     * @param array<string, array<string, mixed>> $map
     */
    private static function respond(array $map): JsonResponse
    {
        return new JsonResponse([] === $map ? new stdClass() : $map);
    }

    /**
     * `?ids=<uuid>,<uuid>,…` → validated, deduplicated RFC 4122 list.
     *
     * Rejects rather than silently skips a malformed id: a typo that quietly
     * dropped one row would surface as a counter stuck at zero, which is the
     * exact failure mode this ticket exists to fix.
     *
     * @return list<string>
     */
    private static function parseIds(Request $request): array
    {
        $raw = $request->query->getString('ids', '');

        $parts = array_values(array_filter(
            array_map(trim(...), explode(',', $raw)),
            static fn (string $part): bool => '' !== $part,
        ));

        if ([] === $parts) {
            throw new BadRequestHttpException('Query parameter "ids" is required and must list at least one id.');
        }

        if (\count($parts) > UsageQueryService::MAX_BATCH_IDS) {
            throw new BadRequestHttpException(\sprintf(
                'Query parameter "ids" accepts at most %d ids, %d given.',
                UsageQueryService::MAX_BATCH_IDS,
                \count($parts),
            ));
        }

        $ids = [];
        foreach ($parts as $part) {
            if (!Uuid::isValid($part)) {
                throw new BadRequestHttpException(\sprintf('Query parameter "ids" contains a malformed id: "%s".', $part));
            }
            // Canonicalise so the cache key matches the one the single-item
            // endpoints write (`Uuid::toRfc4122()`, lower-case, hyphenated).
            $ids[] = Uuid::fromString($part)->toRfc4122();
        }

        return array_values(array_unique($ids));
    }
}
