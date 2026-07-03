<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Import\Contracts\ColumnMappingPort;

/**
 * AGENT-P8-02 (#1984) — AI-assisted column mapping (Fala 4): the tool
 * returns the DETERMINISTIC AutoMapper baseline + the attribute
 * catalogue; the Opus model proposes mappings ONLY for columns the
 * baseline marks "manual" and presents them for the operator to apply
 * in the import wizard. kind=schema routes to Opus; nothing is ever
 * applied by the tool itself.
 */
final readonly class SuggestColumnMappingTool implements AgentToolInterface
{
    public function __construct(
        private ColumnMappingPort $mappings,
    ) {
    }

    public function name(): string
    {
        return 'suggest_column_mapping';
    }

    public function description(): string
    {
        return 'Get the deterministic column-mapping baseline (AutoMapper: code/label/alias/fuzzy) plus the attribute catalogue for a set of import headers. '
            .'Propose mappings ONLY for columns with confidence=manual, citing the sample values; NEVER override an auto match. '
            .'Your proposals are suggestions for the operator to apply in the import wizard - nothing is applied automatically.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'headers' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Column headers from the import file.'],
                'sample_rows' => [
                    'type' => 'array',
                    'items' => ['type' => 'object'],
                    'description' => 'First rows as header=>value objects (for grounding the proposals).',
                ],
            ],
            'required' => ['headers'],
        ];
    }

    public function requiredPermission(): string
    {
        return 'imports.run';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Schema;
    }

    public function execute(array $arguments, AgentToolContext $context): array
    {
        $headers = [];
        $rawHeaders = \is_array($arguments['headers'] ?? null) ? $arguments['headers'] : [];
        foreach ($rawHeaders as $header) {
            if (\is_string($header) && '' !== $header) {
                $headers[] = $header;
            }
        }
        if ([] === $headers) {
            return ['error' => 'headers must be a non-empty list of strings.'];
        }

        $sampleRows = [];
        $rawRows = \is_array($arguments['sample_rows'] ?? null) ? $arguments['sample_rows'] : [];
        foreach ($rawRows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $clean = [];
            foreach ($row as $key => $value) {
                if (\is_string($key) && (\is_string($value) || null === $value)) {
                    $clean[$key] = $value;
                }
            }
            $sampleRows[] = $clean;
        }

        return $this->mappings->suggestMappings($headers, $sampleRows);
    }
}
