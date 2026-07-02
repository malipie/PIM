<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Search\Contracts\CatalogQueryPort;

/**
 * AGENT-P2-02 (#1959) — the "matched N" grounding tool: a hard,
 * RBAC/tenant-scoped count for a filter DSL, cheaper and more explicit
 * than paging through search_catalog. The number it returns is the one
 * that lands in the plan shown to the operator ("1 800 objects will
 * change").
 *
 * Richer statistics (median/min/max) are NOT available through the
 * search engine cheaply - the tool says so instead of estimating
 * (PRD 8.1 median checks arrive with the read-SQL port of P2-03 /
 * future analytics; conscious MVP scope per ticket AC).
 */
final readonly class AggregateCatalogTool implements AgentToolInterface
{
    public function __construct(
        private CatalogQueryPort $catalogQuery,
    ) {
    }

    public function name(): string
    {
        return 'aggregate_count';
    }

    public function description(): string
    {
        return 'Count catalog objects matching a filter DSL (and/or full-text query). Call it to get the exact affected-object count BEFORE proposing any change. '
            .'If the user refers to the current view, omit filter_dsl - the active view filter is applied automatically. Returns matched (exact count).';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'object_kind' => [
                    'type' => 'string',
                    'description' => 'Object kind to count (product, category, asset, custom). Default: product.',
                ],
                'query' => ['type' => 'string', 'description' => 'Optional full-text query narrowing the count.'],
                'filter_dsl' => [
                    'type' => 'object',
                    'description' => 'Canonical filter DSL. Omit to use the active view filter.',
                ],
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

        /** @var array<string, mixed> $filterDslArray */
        $filterDslArray = $filterDsl ?? [];
        $result = $this->catalogQuery->search($kind, $query, $filterDslArray, page: 1, perPage: 1);

        return [
            'matched' => $result->totalHits,
            'degraded' => $result->degraded,
            'note' => $result->degraded
                ? 'Search engine unavailable - the count is NOT verified; tell the user you could not verify.'
                : 'Exact count. Median/min/max statistics are not available through this tool - do not estimate them.',
        ];
    }
}
