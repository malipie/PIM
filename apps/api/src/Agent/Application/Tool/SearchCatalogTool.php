<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Search\Contracts\CatalogQueryPort;

/**
 * AGENT-P2-01 (#1958) — grounding read tool: find objects by full-text
 * query and/or filter DSL, starting from the user's view context (the
 * active filter rides in agent_runs.context and is used when the model
 * passes no filter of its own).
 *
 * Results are a MINIMAL PROJECTION (id, code, kind, status, name,
 * completeness_pct) — deliberately NOT the attribute map, so no
 * attribute value can leak past a per-attribute grant (PRD 10.3 data
 * minimisation; conscious MVP call documented in the PR): grounding
 * needs counts + identities, not values. `degraded=true` from the
 * engine is passed through so the model says "could not verify"
 * instead of fabricating numbers.
 */
final readonly class SearchCatalogTool implements AgentToolInterface
{
    private const int MAX_PER_PAGE = 50;

    public function __construct(
        private CatalogQueryPort $catalogQuery,
    ) {
    }

    public function name(): string
    {
        return 'search_catalog';
    }

    public function description(): string
    {
        return 'Search the catalog with a full-text query and/or a filter DSL. Call it to ground any plan in real counts before proposing changes. '
            .'If the user refers to "these products" / the current view, omit filter_dsl - the active view filter is applied automatically. '
            .'Returns a minimal projection (id, code, status, name, completeness_pct) plus total_hits.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'object_kind' => [
                    'type' => 'string',
                    'description' => 'Object kind to search (product, category, asset, custom). Default: product.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Optional full-text query.',
                ],
                'filter_dsl' => [
                    'type' => 'object',
                    'description' => 'Canonical filter DSL: condition {attr, op, value?} or group {operator: AND|OR, conditions: [...]}. Omit to use the active view filter.',
                ],
                'page' => ['type' => 'integer', 'description' => 'Page number (1-based).'],
                'per_page' => ['type' => 'integer', 'description' => 'Hits per page, max 50.'],
            ],
            'required' => [],
        ];
    }

    public function requiredPermission(): string
    {
        return 'object.read';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function execute(array $arguments, AgentToolContext $context): array
    {
        $kind = \is_string($arguments['object_kind'] ?? null) ? $arguments['object_kind'] : 'product';
        $query = \is_string($arguments['query'] ?? null) ? $arguments['query'] : '';

        $filterDsl = \is_array($arguments['filter_dsl'] ?? null) ? $arguments['filter_dsl'] : null;
        $viewFilter = $context->viewContext['filter_dsl'] ?? null;
        if (null === $filterDsl && \is_array($viewFilter)) {
            $filterDsl = $viewFilter;
        }

        $page = \is_int($arguments['page'] ?? null) ? max(1, $arguments['page']) : 1;
        $perPage = \is_int($arguments['per_page'] ?? null)
            ? min(self::MAX_PER_PAGE, max(1, $arguments['per_page']))
            : 20;

        /** @var array<string, mixed> $filterDslArray */
        $filterDslArray = $filterDsl ?? [];
        $result = $this->catalogQuery->search($kind, $query, $filterDslArray, $page, $perPage);

        return [
            'total_hits' => $result->totalHits,
            'degraded' => $result->degraded,
            'note' => $result->degraded
                ? 'Search engine unavailable - the numbers above are NOT verified; tell the user you could not verify.'
                : null,
            'page' => $page,
            'per_page' => $perPage,
            'hits' => array_map($this->project(...), $result->hits),
        ];
    }

    /**
     * @param array<string, mixed> $hit
     *
     * @return array<string, mixed>
     */
    private function project(array $hit): array
    {
        $attributes = \is_array($hit['attributesIndexed'] ?? null) ? $hit['attributesIndexed'] : [];
        $nameEnvelope = \is_array($attributes['name'] ?? null) ? $attributes['name'] : [];
        $completeness = \is_array($hit['completeness'] ?? null) ? $hit['completeness'] : [];

        return [
            'id' => $hit['id'] ?? null,
            'code' => $hit['code'] ?? null,
            'kind' => $hit['kind'] ?? null,
            'status' => $hit['status'] ?? null,
            'name' => $nameEnvelope['value'] ?? null,
            'completeness_pct' => $completeness['global'] ?? null,
        ];
    }
}
