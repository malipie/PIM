<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Export\Contracts\ExportTriggerPort;

/**
 * AGENT-P3-06 (#1966) — the agent as an ACTION: "export the selected
 * products to XLSX". No approval diff (nothing in the catalog changes);
 * the same Export engine, session and Mercure progress as the admin UI.
 * Proof that the registry carries kind=action tools.
 */
final readonly class TriggerExportTool implements AgentToolInterface
{
    private const array DEFAULT_COLUMNS = ['sku', 'status', 'completeness_pct'];

    public function __construct(
        private ExportTriggerPort $exports,
    ) {
    }

    public function name(): string
    {
        return 'trigger_export';
    }

    public function description(): string
    {
        return 'Start an export of the objects matching a filter DSL to a file (xlsx or csv). Runs asynchronously - '
            .'return the export_session_id and status_url to the user so they can follow progress and download the file. '
            .'Columns accept built-ins (sku, status, completeness_pct, category, created_at, updated_at) or attribute codes.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'object_type_code' => [
                    'type' => 'string',
                    'description' => 'ObjectType code (e.g. product). Default: product.',
                ],
                'filter_dsl' => [
                    'type' => 'object',
                    'description' => 'Selector: canonical filter DSL. Omit to use the active view filter. An empty selector exports EVERY object of the type.',
                ],
                'columns' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Column keys. Default: sku, status, completeness_pct.',
                ],
                'format' => [
                    'type' => 'string',
                    'enum' => ['xlsx', 'csv'],
                    'description' => 'Output format. Default: xlsx.',
                ],
            ],
        ];
    }

    public function requiredPermission(): string
    {
        return 'exports.run';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Action;
    }

    public function execute(array $arguments, AgentToolContext $context): array
    {
        $objectTypeCode = \is_string($arguments['object_type_code'] ?? null) ? $arguments['object_type_code'] : 'product';
        $formatRaw = $arguments['format'] ?? null;
        $format = \in_array($formatRaw, ['xlsx', 'csv'], true) ? $formatRaw : 'xlsx';

        $columns = [];
        $rawColumns = \is_array($arguments['columns'] ?? null) ? $arguments['columns'] : [];
        foreach ($rawColumns as $column) {
            if (\is_string($column) && '' !== $column) {
                $columns[] = $column;
            }
        }
        if ([] === $columns) {
            $columns = self::DEFAULT_COLUMNS;
        }

        $filterDsl = \is_array($arguments['filter_dsl'] ?? null) ? $arguments['filter_dsl'] : null;
        $viewFilter = $context->viewContext['filter_dsl'] ?? null;
        if (null === $filterDsl && \is_array($viewFilter)) {
            $filterDsl = $viewFilter;
        }
        /** @var array<string, mixed> $filterDslArray */
        $filterDslArray = $filterDsl ?? [];

        $sessionId = $this->exports->trigger(
            userId: $context->userId,
            objectTypeCode: $objectTypeCode,
            filterDsl: $filterDslArray,
            columns: $columns,
            format: $format,
        );

        return [
            'export_session_id' => $sessionId->toRfc4122(),
            'status_url' => \sprintf('/api/exports/sessions/%s', $sessionId->toRfc4122()),
            'format' => $format,
            'note' => 'Export runs asynchronously - progress streams on the export Mercure topic; the download link appears on the session once completed.',
        ];
    }
}
