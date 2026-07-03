<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Export\Contracts\FeedAssistPort;

/**
 * AGENT-P7-01 (#1981) — UC3 "podpowiedz strukturę feedu": the agent
 * asks the XML configurator ENGINE for a template structure evaluated
 * on a sample of real data (boundary §5.6 - never a bespoke
 * serializer). Registered like any tool: zero loop changes prove the
 * thin-layer architecture (ADR-0024 b).
 */
final readonly class SuggestFeedStructureTool implements AgentToolInterface
{
    public function __construct(
        private FeedAssistPort $feeds,
    ) {
    }

    public function name(): string
    {
        return 'suggest_feed_structure';
    }

    public function description(): string
    {
        return 'Suggest an XML feed structure for a marketplace template (google_shopping, ceneo, meta, custom) evaluated on a SAMPLE of real data. '
            .'Returns the slot list with default sources, a sample XML and a health report (missing required slots per SKU) - '
            .'present the health findings to the user so they know which attributes to fill or remap.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'template' => [
                    'type' => 'string',
                    'enum' => ['google_shopping', 'ceneo', 'meta', 'custom'],
                    'description' => 'Built-in feed template to evaluate.',
                ],
                'object_type_code' => ['type' => 'string', 'description' => 'ObjectType code. Default: product.'],
                'filter_dsl' => ['type' => 'object', 'description' => 'Sample selector (canonical filter DSL). Omit to use the active view filter.'],
                'sample_limit' => ['type' => 'integer', 'description' => 'Sample size (1-25). Default: 5.'],
            ],
            'required' => ['template'],
        ];
    }

    public function requiredPermission(): string
    {
        return 'integration.admin';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function execute(array $arguments, AgentToolContext $context): array
    {
        $template = \is_string($arguments['template'] ?? null) ? $arguments['template'] : 'google_shopping';
        $objectTypeCode = \is_string($arguments['object_type_code'] ?? null) ? $arguments['object_type_code'] : 'product';
        $sampleLimit = \is_int($arguments['sample_limit'] ?? null) ? $arguments['sample_limit'] : 5;

        $filterDsl = \is_array($arguments['filter_dsl'] ?? null) ? $arguments['filter_dsl'] : null;
        $viewFilter = $context->viewContext['filter_dsl'] ?? null;
        if (null === $filterDsl && \is_array($viewFilter)) {
            $filterDsl = $viewFilter;
        }
        /** @var array<string, mixed> $filterDslArray */
        $filterDslArray = $filterDsl ?? [];

        return $this->feeds->suggestStructure($template, $objectTypeCode, $filterDslArray, $sampleLimit);
    }
}
