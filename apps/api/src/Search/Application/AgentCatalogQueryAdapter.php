<?php

declare(strict_types=1);

namespace App\Search\Application;

use App\Catalog\Application\Filter\FilterDslResolver;
use App\Catalog\Domain\ObjectKind;
use App\Search\Contracts\CatalogQueryPort;
use App\Search\Contracts\CatalogQueryResult;
use InvalidArgumentException;
use Throwable;

/**
 * AGENT-P2-01 (#1958) — the Contracts adapter over the existing search
 * engine (CatalogSearchService + FilterDslResolver): the agent grounds
 * its plans through EXACTLY the pipeline the manual list view uses —
 * same validation, same Meili filter compilation, same tenant scoping,
 * same `degraded` semantics on engine outage.
 */
final readonly class AgentCatalogQueryAdapter implements CatalogQueryPort
{
    public function __construct(
        private CatalogSearchService $searchService,
        private FilterDslResolver $filterResolver,
    ) {
    }

    public function search(string $kind, string $query = '', array $filterDsl = [], int $page = 1, int $perPage = 20): CatalogQueryResult
    {
        $objectKind = ObjectKind::tryFrom($kind);
        if (null === $objectKind) {
            throw new InvalidArgumentException(\sprintf('Unknown object kind "%s".', $kind));
        }

        $filterExpression = null;
        if ([] !== $filterDsl) {
            try {
                $this->filterResolver->validate($filterDsl);
                $filterExpression = $this->filterResolver->toMeilisearchFilter($filterDsl);
            } catch (Throwable $invalid) {
                // Bad DSL is a caller error (the model gets it back as an
                // error tool_result and can correct itself).
                throw new InvalidArgumentException('Invalid filter DSL: '.$invalid->getMessage(), previous: $invalid);
            }
        }

        $result = $this->searchService->search(
            kind: $objectKind,
            query: $query,
            page: max(1, $page),
            perPage: max(1, $perPage),
            customFilterExpression: $filterExpression,
        );

        return new CatalogQueryResult(
            hits: $result['hits'],
            totalHits: $result['totalHits'],
            degraded: $result['degraded'],
        );
    }
}
