<?php

declare(strict_types=1);

namespace App\Search\Application;

use App\Catalog\Domain\ObjectKind;
use App\Identity\Application\CurrentTenantProvider;
use App\Search\Infrastructure\MeilisearchClientFactory;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Meilisearch\MeiliFilterLiteral;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Throwable;

/**
 * Per-kind catalog search front-end (#52 / 0.5.4).
 *
 * Wraps Meilisearch's `search()` call with:
 *   - mandatory tenant scope (`filter=tenantId=<auth tenant>`) — hub
 *     answers across every tenant unless we constrain it here;
 *   - optional facet distribution (`?facets=brand,category,...`) so
 *     the admin sidebar can render counts;
 *   - optional highlighting (`?highlight=true`) wrapping match terms
 *     in `<em>` so the UI doesn't have to re-tokenise.
 *
 * Pagination is offset-based via Meili's `offset` + `limit` — search
 * lists are short-lived (UX rarely deep-pages search), so a cursor is
 * overkill. The list path under `/api/products` (#41) keeps the
 * cursor pagination for full catalog scans.
 */
final readonly class CatalogSearchService
{
    private LoggerInterface $logger;

    public function __construct(
        private MeilisearchClientFactory $clientFactory,
        private CurrentTenantProvider $tenantProvider,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param array<string, scalar|list<scalar>>             $filters                plain key→value / key→[v1,v2] OR matches on top of tenant scope
     * @param list<string>                                   $facets                 Attribute codes to facet on
     * @param array<string, mixed>                           $extra                  forward-compat — Meili options not surfaced here yet (sort, ranking)
     * @param array<string, array{gte?: float, lte?: float}> $rangeFilters           numeric range filters (UI-02.24); mapped to Meili `key >= N AND key <= N`
     * @param ?string                                        $customFilterExpression VIEW-10 — pre-built Meilisearch filter expression from FilterDslResolver::toMeilisearchFilter() AND-merged with tenant + flat filters
     *
     * AUD-070 (#1614) — the result carries a `degraded` flag. It is `false`
     * for every normal answer (including a legitimately empty hit list) and
     * `true` only when the Meilisearch backend itself failed (connection /
     * timeout / protocol error). Callers MUST distinguish the two: a degraded
     * search is a backend outage, NOT "zero results", and surfacing it as an
     * empty list silently misleads the operator. The presentation layer maps
     * `degraded:true` to a 503 problem+json instead of an empty `200`.
     *
     * @return array{hits: list<array<string, mixed>>, totalHits: int, facetDistribution: array<string, mixed>, processingTimeMs: int, degraded: bool}
     */
    public function search(
        ObjectKind $kind,
        string $query,
        array $filters = [],
        array $facets = [],
        int $page = 1,
        int $perPage = 30,
        bool $highlight = false,
        array $extra = [],
        array $rangeFilters = [],
        ?string $customFilterExpression = null,
    ): array {
        // ULV-02 (#983) — custom kinds land in the consolidated `objects`
        // index alongside built-ins; no early-return skip anymore.

        $tenant = $this->tenantProvider->getCurrent();
        if (!$tenant instanceof Tenant) {
            // No active tenant context — defence-in-depth: refuse to
            // hit the hub at all. Cross-tenant leak would break the
            // multi-tenant isolation contract.
            return $this->emptyResult();
        }

        // AUD-004 (#1574) — every user-supplied filter/range key is
        // interpolated into the Meili filter expression below. Without a
        // whitelist a key carrying a Meili operator (e.g.
        // `parentId IS NULL OR tenantId`) injects a low-precedence `OR` that
        // closes the AND-scoped tenant clause → cross-tenant read. Validate
        // every key against the index's `filterableAttributes` (the same
        // source already trusted for facets) and 400 on anything else.
        $allowedKeys = $this->filterableAttributesFor($kind);
        $this->assertFilterKeys(array_keys($filters), $allowedKeys);
        $this->assertFilterKeys(array_keys($rangeFilters), $allowedKeys);

        $tenantFilter = \sprintf('tenantId = "%s"', $tenant->getId()->toRfc4122());
        $extraFilters = [];
        // ULV-02 — every kind shares one index, so we constrain by the
        // `kind` filterable to preserve the legacy "show me only products"
        // behaviour callers still expect.
        $extraFilters[] = \sprintf('kind = "%s"', $kind->value);
        foreach ($filters as $key => $value) {
            if (\is_array($value)) {
                $orParts = [];
                foreach ($value as $v) {
                    $orParts[] = \sprintf('%s = %s', $key, MeiliFilterLiteral::quote((string) $v));
                }
                if ([] !== $orParts) {
                    $extraFilters[] = '('.implode(' OR ', $orParts).')';
                }
                continue;
            }
            $extraFilters[] = \sprintf('%s = %s', $key, MeiliFilterLiteral::quote((string) $value));
        }
        foreach ($rangeFilters as $key => $range) {
            if (isset($range['gte'])) {
                $extraFilters[] = \sprintf('%s >= %s', $key, $range['gte']);
            }
            if (isset($range['lte'])) {
                $extraFilters[] = \sprintf('%s <= %s', $key, $range['lte']);
            }
        }
        if (null !== $customFilterExpression && '' !== trim($customFilterExpression)) {
            $extraFilters[] = '('.$customFilterExpression.')';
        }
        // ULV-02 — `$extraFilters` always carries at least the `kind`
        // filter we added above, so the conditional join is unconditional.
        $filterExpression = trim($tenantFilter.' AND '.implode(' AND ', $extraFilters));

        $options = [
            'filter' => $filterExpression,
            'limit' => $perPage,
            'offset' => max(0, ($page - 1) * $perPage),
        ];
        if ([] !== $facets) {
            // Defense-in-depth: Meilisearch rejects facet requests that
            // reference non-filterable attributes with "Invalid facet
            // distribution" and the entire search returns empty. Filter
            // the requested facets against what the index actually supports
            // so a stale FE list (e.g. legacy `family` facet) degrades to
            // "no breakdown" instead of "0 hits".
            $allowed = $this->filterableAttributesFor($kind);
            $valid = array_values(array_filter($facets, static fn (string $f) => \in_array($f, $allowed, true)));
            if ([] !== $valid) {
                $options['facets'] = $valid;
            }
        }
        if ($highlight) {
            $options['attributesToHighlight'] = ['*'];
            $options['highlightPreTag'] = '<em>';
            $options['highlightPostTag'] = '</em>';
        }
        $options = array_merge($options, $extra);

        try {
            $client = $this->clientFactory->create();
            $result = $client->index(IndexSettingsTemplate::indexName())->search($query, $options);
            $raw = $result->toArray();
        } catch (Throwable $e) {
            // AUD-070 (#1614) — the Meili backend is unreachable / errored.
            // Do NOT collapse this to an empty result: an empty list is
            // indistinguishable from "no matches" and silently misleads the
            // operator into thinking the catalog is empty. Flag the result as
            // `degraded` so the controller can answer 503 problem+json. The
            // products *list* path keeps its Postgres fallback; search has no
            // equivalent fallback in MVP, so signalling the outage is the
            // correct behaviour here.
            $this->logger->warning('Meilisearch query failed: {message}', [
                'message' => $e->getMessage(),
                'kind' => $kind->value,
                'query' => $query,
            ]);

            return $this->degradedResult();
        }

        $rawHits = $raw['hits'] ?? [];
        $hits = [];
        if (\is_array($rawHits)) {
            foreach ($rawHits as $hit) {
                if (\is_array($hit)) {
                    $normalised = [];
                    foreach ($hit as $k => $v) {
                        $normalised[(string) $k] = $v;
                    }
                    $hits[] = $normalised;
                }
            }
        }

        $rawTotal = $raw['estimatedTotalHits'] ?? $raw['totalHits'] ?? 0;
        $rawProcessing = $raw['processingTimeMs'] ?? 0;
        $rawFacets = $raw['facetDistribution'] ?? null;
        $facets = [];
        if (\is_array($rawFacets)) {
            foreach ($rawFacets as $k => $v) {
                $facets[(string) $k] = $v;
            }
        }

        return [
            'hits' => $hits,
            'totalHits' => \is_numeric($rawTotal) ? (int) $rawTotal : 0,
            'facetDistribution' => $facets,
            'processingTimeMs' => \is_numeric($rawProcessing) ? (int) $rawProcessing : 0,
            'degraded' => false,
        ];
    }

    /**
     * A legitimately empty answer (e.g. no tenant context). `degraded` stays
     * `false` — there is no backend outage, the query simply has no results.
     *
     * @return array{hits: list<array<string, mixed>>, totalHits: int, facetDistribution: array<string, mixed>, processingTimeMs: int, degraded: bool}
     */
    private function emptyResult(): array
    {
        return [
            'hits' => [],
            'totalHits' => 0,
            'facetDistribution' => [],
            'processingTimeMs' => 0,
            'degraded' => false,
        ];
    }

    /**
     * AUD-070 (#1614) — the search backend failed. Shaped identically to an
     * empty result but flagged `degraded:true` so the controller answers 503
     * instead of a misleading empty `200`.
     *
     * @return array{hits: list<array<string, mixed>>, totalHits: int, facetDistribution: array<string, mixed>, processingTimeMs: int, degraded: bool}
     */
    private function degradedResult(): array
    {
        return [
            'hits' => [],
            'totalHits' => 0,
            'facetDistribution' => [],
            'processingTimeMs' => 0,
            'degraded' => true,
        ];
    }

    /**
     * AUD-004 (#1574) — validate user-supplied filter keys against the
     * index's filterable attributes before any value is interpolated into
     * the Meili expression.
     *
     * Two rules:
     *   1. `tenantId` is a reserved, non-mixable scope — it is always
     *      AND-merged separately ({@see $tenantFilter}) and must never be
     *      driven by a user filter, otherwise the scope can be widened.
     *   2. Every other key must be a known filterable attribute. Anything
     *      else (unknown field, or a string smuggling a Meili operator such
     *      as `parentId IS NULL OR tenantId`) is rejected with a 400.
     *
     * @param list<int|string> $keys
     * @param list<string>     $allowed
     */
    private function assertFilterKeys(array $keys, array $allowed): void
    {
        foreach ($keys as $key) {
            $key = (string) $key;
            if ('tenantId' === $key) {
                throw new BadRequestHttpException('Filter key "tenantId" is reserved and cannot be set explicitly.');
            }
            if (!\in_array($key, $allowed, true)) {
                throw new BadRequestHttpException(\sprintf('Unknown filter attribute "%s".', $key));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function filterableAttributesFor(ObjectKind $kind): array
    {
        // ULV-02 — single index settings; `$kind` retained as legacy hint.
        $settings = new IndexSettingsTemplate()->settingsFor();
        $raw = $settings['filterableAttributes'] ?? [];
        if (!\is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, 'is_string'));
    }
}
