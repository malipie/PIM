<?php

declare(strict_types=1);

namespace App\Search\Presentation\Controller;

use App\Catalog\Application\Filter\FilterDslResolver;
use App\Catalog\Application\Filter\FilterUrlSerializer;
use App\Catalog\Domain\Entity\SmartFilterPreset;
use App\Catalog\Domain\ObjectKind;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Search\Application\CatalogSearchService;
use App\Search\Application\ScopedFilterPrefilter;
use App\Shared\Application\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * VIEW-11 (#542) — bulk selection helper for the cross-page selection
 * toolbar.
 *
 * The flat selection state in the UI tracks per-row checkbox toggles,
 * but operators want to escalate to *„select all matching"* without
 * pagination. The toolbar issues a POST here with the current filter
 * payload (smart preset OR base64 DSL), the backend resolves the
 * Meilisearch filter expression, paginates through up to {@see HARD_CAP}
 * matching documents, and returns the bare UUID list.
 *
 * Hard cap 10k IDs per request (PRD §14 R-35 mitigation): heavier
 * selections degrade async bulk handlers; payload >10k IDs strains the
 * client JSON parser. Selections beyond the cap surface
 * `capped: true` + `totalMatched` so the toolbar can warn the user.
 */
final class BulkSelectionController
{
    public const int HARD_CAP = 10_000;
    private const int PAGE_SIZE = 1_000;

    public function __construct(
        private readonly CatalogSearchService $searchService,
        private readonly FilterDslResolver $filterDslResolver,
        private readonly FilterUrlSerializer $filterUrlSerializer,
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly ScopedFilterPrefilter $scopedPrefilter,
    ) {
    }

    #[Route('/api/products/select-all-matching', name: 'pim_bulk_select_all_matching', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'products', action: 'view')]
    public function selectAllMatching(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $query = \is_string($body['q'] ?? null) ? trim($body['q']) : '';
        $customFilterExpression = $this->resolveCustomFilter($body);
        [$filters, $rangeFilters] = $this->resolveFlatFilters($body);

        // Mirror the list view's variant-tree gate: when the operator
        // sees only masters (`variants_mode=tree`, default in the FE),
        // selecting "all matching" must not pull variants in — otherwise
        // the badge count exceeds the visible row count and bulk actions
        // run on rows the operator can't see.
        $variantsMode = \is_string($body['variants_mode'] ?? null) ? $body['variants_mode'] : 'tree';
        if ('tree' === $variantsMode) {
            $masterClause = 'parentId IS NULL';
            $customFilterExpression = null === $customFilterExpression
                ? $masterClause
                : '('.$customFilterExpression.') AND '.$masterClause;
        }

        $limit = isset($body['limit']) && is_numeric($body['limit'])
            ? min(self::HARD_CAP, max(1, (int) $body['limit']))
            : self::HARD_CAP;

        $ids = [];
        $page = 1;
        $totalMatched = 0;
        while (true) {
            $result = $this->searchService->search(
                kind: ObjectKind::Product,
                query: $query,
                filters: $filters,
                page: $page,
                perPage: self::PAGE_SIZE,
                rangeFilters: $rangeFilters,
                customFilterExpression: $customFilterExpression,
            );
            // AUD-070 (#1614) — the search backend is down. Returning an empty
            // selection here would silently run "select all matching" against
            // zero rows; surface the outage as 503 problem+json instead.
            if ($result['degraded']) {
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
            $totalMatched = $result['totalHits'];
            foreach ($result['hits'] as $hit) {
                if (isset($hit['id']) && \is_string($hit['id'])) {
                    $ids[] = $hit['id'];
                }
                if (\count($ids) >= $limit) {
                    break 2;
                }
            }
            if (\count($result['hits']) < self::PAGE_SIZE) {
                break; // no more pages
            }
            ++$page;
        }

        return new JsonResponse([
            'ids' => $ids,
            'totalMatched' => $totalMatched,
            'capped' => \count($ids) < $totalMatched,
            'limit' => $limit,
        ]);
    }

    /**
     * Mirror SearchController's scalar/list/range coercion so the list and
     * select-all endpoint evaluate the same active search scope (#2987).
     *
     * @param array<string, mixed> $body
     *
     * @return array{array<string, scalar|list<scalar>>, array<string, array{gte?: float, lte?: float}>}
     */
    private function resolveFlatFilters(array $body): array
    {
        $filters = [];
        $rawFilters = $body['filters'] ?? null;
        if (\is_array($rawFilters)) {
            foreach ($rawFilters as $key => $value) {
                if (!\is_string($key)) {
                    continue;
                }
                if (\is_scalar($value)) {
                    $filters[$key] = $value;
                    continue;
                }
                if (\is_array($value)) {
                    /** @var list<scalar> $entries */
                    $entries = array_values(array_filter($value, \is_scalar(...)));
                    $filters[$key] = $entries;
                }
            }
        }

        $rangeFilters = [];
        $rawRanges = $body['range_filters'] ?? null;
        if (\is_array($rawRanges)) {
            foreach ($rawRanges as $key => $value) {
                if (!\is_string($key) || !\is_array($value)) {
                    continue;
                }
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
            }
        }

        return [$filters, $rangeFilters];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function resolveCustomFilter(array $body): ?string
    {
        $smartPreset = $body['smart_preset'] ?? null;
        if (\is_string($smartPreset) && '' !== trim($smartPreset)) {
            $preset = $this->loadPreset(trim($smartPreset));
            if (null === $preset) {
                throw new NotFoundHttpException(\sprintf('Smart filter preset "%s" not found.', $smartPreset));
            }

            return $this->compileCustomFilter($preset->getQuery());
        }

        $blob = $body['filter'] ?? null;
        if (\is_string($blob) && '' !== trim($blob)) {
            $dsl = $this->filterUrlSerializer->fromBase64(trim($blob));
            if ([] === $dsl) {
                return null;
            }

            return $this->compileCustomFilter($dsl);
        }

        return null;
    }

    /**
     * #2673 — scoped documents run through the SQL prefilter (see
     * SearchController::compileCustomFilter); the truncation flag is
     * irrelevant here — the selection endpoint applies its own cap via
     * `totalMatched` / `capped`.
     *
     * @param array<string, mixed> $dsl
     */
    private function compileCustomFilter(array $dsl): string
    {
        if (FilterDslResolver::hasScope($dsl)) {
            [$expression] = $this->scopedPrefilter->compile($dsl);

            return $expression;
        }

        return $this->filterDslResolver->toMeilisearchFilter($dsl);
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
