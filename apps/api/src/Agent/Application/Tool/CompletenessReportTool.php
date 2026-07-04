<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Catalog\Contracts\Query\CompletenessReportPort;

/**
 * AGENT-P2-03 (#1960) — grounding for enrichment plans: how complete
 * one ObjectType's data is and which required attributes are missing
 * how often ("62% average - `ean` missing on 340 products"). Read-only
 * over the existing completeness scoring; filling the gaps is the
 * write tool's job (P3-01) and always goes through approval.
 */
final readonly class CompletenessReportTool implements AgentToolInterface, ProvidesQuickActionInterface
{
    public function __construct(
        private CompletenessReportPort $reports,
    ) {
    }

    public function name(): string
    {
        return 'completeness_report';
    }

    public function description(): string
    {
        return 'Aggregate completeness report for one object type: total objects, average completeness percent, how many fall below a threshold, and which required attributes are missing on how many objects. '
            .'Call it before proposing enrichment ("bring category X to 90% completeness").';
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
                'threshold_pct' => [
                    'type' => 'integer',
                    'description' => 'Completeness threshold percent for the below-threshold count. Default: 90.',
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

    public function quickAction(): AgentQuickAction
    {
        return new AgentQuickAction(
            id: $this->name(),
            label: ['pl' => 'Raport kompletności', 'en' => 'Completeness report'],
            prompt: [
                'pl' => 'Pokaż raport kompletności produktów',
                'en' => 'Show the product completeness report',
            ],
            priority: 40,
        );
    }

    public function execute(array $arguments, AgentToolContext $context): array
    {
        $code = \is_string($arguments['object_type_code'] ?? null) ? $arguments['object_type_code'] : 'product';
        $threshold = \is_int($arguments['threshold_pct'] ?? null) ? min(100, max(1, $arguments['threshold_pct'])) : 90;

        $report = $this->reports->report($code, $threshold);

        return [
            'object_type' => $report->objectTypeCode,
            'total_objects' => $report->totalObjects,
            'average_pct' => $report->averagePct,
            'below_threshold_count' => $report->belowThresholdCount,
            'threshold_pct' => $report->thresholdPct,
            'missing_required' => $report->missingRequired,
        ];
    }
}
