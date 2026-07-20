<?php

declare(strict_types=1);

namespace App\Search\Presentation\Controller;

use App\Catalog\Application\Filter\FilterDslResolver;
use App\Catalog\Application\Filter\FilterUrlSerializer;
use App\Catalog\Domain\Entity\SmartFilterPreset;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Search\Application\CatalogSearchService;
use App\Search\Application\ScopedFilterPrefilter;
use App\Shared\Application\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

use const FILTER_VALIDATE_BOOLEAN;

/**
 * `/api/{kind}/search` endpoints (#52 / 0.5.4).
 *
 * Three sugar paths mirror the catalog routing: `/api/products/search`,
 * `/api/categories/search`, `/api/assets/search`. Each delegates to
 * {@see CatalogSearchService} with the matching ObjectKind.
 *
 * Response shape (JSON, not JSON-LD): `{hits, totalHits,
 * facetDistribution, processingTimeMs, page, perPage}`. Search is a
 * front-end ergonomic surface — the canonical resource representation
 * lives behind `/api/products/{id}` etc.
 *
 * Authorization: `is_granted('ROLE_USER')` — voter-level checks
 * (object.read) are layered on the resource read paths; search
 * does not surface row-level data beyond what the indexer pushed,
 * and tenant scoping in the service guards cross-tenant leakage.
 */
final class SearchController
{
    public function __construct(
        private readonly CatalogSearchService $searchService,
        private readonly FilterDslResolver $filterDslResolver,
        private readonly FilterUrlSerializer $filterUrlSerializer,
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly ObjectTypeRepositoryInterface $objectTypes,
        private readonly ScopedFilterPrefilter $scopedPrefilter,
    ) {
    }

    #[Route('/api/search/products', name: 'pim_search_products', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'products', action: 'view')]
    public function products(Request $request): JsonResponse
    {
        return $this->run($request, ObjectKind::Product);
    }

    #[Route('/api/search/categories', name: 'pim_search_categories', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'category', action: 'read')]
    public function categories(Request $request): JsonResponse
    {
        return $this->run($request, ObjectKind::Category);
    }

    #[Route('/api/search/assets', name: 'pim_search_assets', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'asset', action: 'read')]
    public function assets(Request $request): JsonResponse
    {
        return $this->run($request, ObjectKind::Asset);
    }

    /**
     * UP-06 (#1024) — universal search route that scopes by `objectTypeId`
     * instead of a hard-coded `ObjectKind`. Powers `/objects/:slug` so
     * custom kinds (`samochody`, `vacancies`, etc.) get the same
     * Meilisearch + facets + smart presets + filter-DSL surface that
     * `/products` enjoys via the per-kind sugar route above.
     *
     * The query param `objectTypeId` is mandatory; we resolve the
     * ObjectType to derive its `ObjectKind` (so the existing `kind`
     * filter still scopes the consolidated `objects` index), then
     * AND-merge an `objectTypeId = "..."` filter so two custom kinds
     * sharing `kind=custom` stay isolated.
     */
    #[Route('/api/search/objects', name: 'pim_search_objects', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'products', action: 'view')]
    public function objects(Request $request): JsonResponse
    {
        $objectTypeIdRaw = $request->query->get('objectTypeId');
        if (!\is_string($objectTypeIdRaw) || '' === trim($objectTypeIdRaw)) {
            throw new BadRequestHttpException('objectTypeId query parameter is required.');
        }
        if (!Uuid::isValid($objectTypeIdRaw)) {
            throw new BadRequestHttpException('objectTypeId must be a valid UUID.');
        }
        $objectType = $this->objectTypes->findById(Uuid::fromString($objectTypeIdRaw));
        if (null === $objectType) {
            throw new NotFoundHttpException(\sprintf('ObjectType "%s" was not found.', $objectTypeIdRaw));
        }

        return $this->run($request, $objectType->getKind(), $objectType->getId()->toRfc4122());
    }

    private function run(Request $request, ObjectKind $kind, ?string $objectTypeId = null): JsonResponse
    {
        // VIEW-20 (#551) — text search lives under `?query=` so it does not
        // collide with `?q=<base64-blob>` introduced by VIEW-10 for the
        // filter DSL URL serializer. Falling back to `?q=` is kept for
        // backwards compatibility ONLY when the value clearly isn't a
        // base64 blob (no padding, short, contains spaces/non-base64 chars).
        $query = $request->query->get('query') ?? '';

        $filters = [];
        $rangeFilters = [];
        /** @var array<string, mixed> $rawFilters */
        $rawFilters = $request->query->all('filter');
        foreach ($rawFilters as $key => $value) {
            if (\is_array($value)) {
                // Range syntax: filter[key][gte]=50 / filter[key][lte]=90 (UI-02.24).
                $isAssoc = array_keys($value) !== range(0, \count($value) - 1);
                if ($isAssoc && (isset($value['gte']) || isset($value['lte']))) {
                    $range = [];
                    if (isset($value['gte']) && is_numeric($value['gte'])) {
                        $range['gte'] = (float) $value['gte'];
                    }
                    if (isset($value['lte']) && is_numeric($value['lte'])) {
                        $range['lte'] = (float) $value['lte'];
                    }
                    if ([] !== $range) {
                        $rangeFilters[$key] = $range;
                    }
                    continue;
                }
                /** @var list<scalar> $coerced */
                $coerced = array_values(array_filter($value, 'is_scalar'));
                $filters[$key] = $coerced;
                continue;
            }
            if (\is_scalar($value)) {
                $filters[$key] = $value;
            }
        }

        $facetsParam = $request->query->get('facets', '');
        $facets = '' === $facetsParam ? [] : array_values(array_filter(
            array_map('trim', explode(',', $facetsParam)),
            static fn (string $s): bool => '' !== $s,
        ));

        $page = max(1, (int) ($request->query->get('page') ?? 1));
        // VIEW-26 (#557) — page size selector wykładnia 20/50/100/200,
        // więc cap podniesiony ze 100 do 200.
        $perPage = min(200, max(1, (int) ($request->query->get('perPage') ?? 30)));
        $highlight = filter_var($request->query->get('highlight'), FILTER_VALIDATE_BOOLEAN);
        // VIEW-11 (#542) — count-only shortcut for cross-page selection
        // toolbar. Skips hit hydration + facet aggregation; Meilisearch
        // still has to evaluate the filter, but the payload is ~5x cheaper.
        $countOnly = filter_var($request->query->get('count_only'), FILTER_VALIDATE_BOOLEAN);
        if ($countOnly) {
            $perPage = 1;
            $facets = [];
        }

        // VIEW-10 (#538) — `smart_preset` + `filter` query params compile
        // a FilterDsl through the resolver and AND-merge the resulting
        // Meilisearch expression with the existing flat filters above.
        [$customFilterExpression, $scopeTruncated] = $this->resolveCustomFilter($request);

        // UP-06 (#1024) — `/api/search/objects` scopes by objectTypeId on
        // top of the kind filter so two custom ObjectTypes sharing
        // `kind=custom` (e.g. `samochody` + `vacancies`) stay isolated.
        if (null !== $objectTypeId) {
            $filters['objectTypeId'] = $objectTypeId;
        }

        $result = $this->searchService->search(
            kind: $kind,
            query: $query,
            filters: $filters,
            facets: $facets,
            page: $page,
            perPage: $perPage,
            highlight: $highlight,
            rangeFilters: $rangeFilters,
            customFilterExpression: $customFilterExpression,
        );

        // AUD-070 (#1614) — the search backend is down. Answering an empty
        // `200` here would read as "no products match", silently misleading
        // the operator. Signal the outage with a 503 problem+json so the FE
        // can render a "search temporarily unavailable" state distinct from
        // an empty result set.
        if ($result['degraded']) {
            return $this->searchDegradedResponse();
        }

        if ($countOnly) {
            return new JsonResponse([
                'totalHits' => $result['totalHits'],
                'processingTimeMs' => $result['processingTimeMs'],
                'scopeTruncated' => $scopeTruncated,
            ]);
        }

        return new JsonResponse([
            'hits' => $result['hits'],
            'totalHits' => $result['totalHits'],
            'facetDistribution' => $result['facetDistribution'],
            'processingTimeMs' => $result['processingTimeMs'],
            'page' => $page,
            'perPage' => $perPage,
            // #2673 — true when a value-context scope matched more objects
            // than the SQL→Meili id prefilter cap; the hit list is then
            // approximate and the FE renders a "narrow your filter" note.
            'scopeTruncated' => $scopeTruncated,
        ]);
    }

    /**
     * AUD-070 (#1614) — RFC 7807 problem+json for a search-backend outage.
     * 503 (not 500) because the condition is transient and retryable; the
     * dedicated `type` URN lets the FE branch on "search down" vs. a generic
     * server error and render the right empty/error state.
     */
    private function searchDegradedResponse(): JsonResponse
    {
        return new JsonResponse(
            [
                'type' => 'urn:pim:errors:search-degraded',
                'title' => 'Search Temporarily Unavailable',
                'status' => Response::HTTP_SERVICE_UNAVAILABLE,
                'detail' => 'The search backend is currently unavailable. This is not an empty result — please retry shortly.',
            ],
            Response::HTTP_SERVICE_UNAVAILABLE,
            ['Content-Type' => 'application/problem+json; charset=utf-8'],
        );
    }

    /**
     * Resolve `?smart_preset` and `?filter` query params into a
     * Meilisearch filter expression string (`null` if neither is present)
     * plus the #2673 scope-truncation flag.
     *
     * @return array{0: ?string, 1: bool}
     */
    private function resolveCustomFilter(Request $request): array
    {
        $smartPreset = $request->query->get('smart_preset');
        if (\is_string($smartPreset) && '' !== trim($smartPreset)) {
            $preset = $this->loadPreset(trim($smartPreset));
            if (null === $preset) {
                throw new NotFoundHttpException(\sprintf('Smart filter preset "%s" not found.', $smartPreset));
            }

            return $this->compileCustomFilter($preset->getQuery());
        }

        $blob = $request->query->get('q');
        if (\is_string($blob) && '' !== trim($blob)) {
            // VIEW-20 (#551) — `?q=` is now blob-only, but older FE versions
            // (pre-VIEW-20) may still send raw text here. If decoding fails
            // we silently treat the param as text-search and skip the filter
            // path rather than 400-ing the whole request.
            try {
                $dsl = $this->filterUrlSerializer->fromBase64(trim($blob));
            } catch (BadRequestHttpException) {
                return [null, false];
            }
            if ([] === $dsl) {
                return [null, false];
            }

            return $this->compileCustomFilter($dsl);
        }

        return [null, false];
    }

    /**
     * #2673 — scoped documents (value context) cannot compile to a Meili
     * filter (the document holds global values only); they run through the
     * SQL prefilter and reach Meili as an `id IN [...]` expression.
     *
     * @param array<string, mixed> $dsl
     *
     * @return array{0: string, 1: bool}
     */
    private function compileCustomFilter(array $dsl): array
    {
        if (FilterDslResolver::hasScope($dsl)) {
            return $this->scopedPrefilter->compile($dsl);
        }

        return [$this->filterDslResolver->toMeilisearchFilter($dsl), false];
    }

    private function loadPreset(string $idOrSlug): ?SmartFilterPreset
    {
        $repo = $this->em->getRepository(SmartFilterPreset::class);
        if (1 === preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $idOrSlug)) {
            $preset = $repo->find(Uuid::fromString($idOrSlug));
            if ($preset instanceof SmartFilterPreset) {
                return $preset;
            }
        }

        $tenant = $this->tenantContext->get();
        // Try system-shipped (tenant=null) first, then tenant-owned.
        $bySlug = $repo->findBy(['slug' => $idOrSlug]);
        foreach ($bySlug as $candidate) {
            $candidateTenant = $candidate->getTenant();
            if (null === $candidateTenant) {
                return $candidate;
            }
            if (null !== $tenant && $candidateTenant->getId()->equals($tenant->getId())) {
                return $candidate;
            }
        }

        return null;
    }
}
