<?php

declare(strict_types=1);

namespace App\Import\Contracts;

/**
 * AGENT-P8-02 (#1984) — the deterministic AutoMapper (code/label/alias/
 * Levenshtein) exposed as the agent's BASELINE: the port returns its
 * suggestions per column plus the attribute catalogue, and the Opus
 * model proposes mappings only where the deterministic pass says
 * "manual". Assist only - the operator applies mappings in the import
 * wizard; nothing here writes anything.
 */
interface ColumnMappingPort
{
    /**
     * @param list<string>                     $headers
     * @param list<array<string, string|null>> $sampleRows header => cell (first N rows)
     *
     * @return array{suggestions: list<array{header: string, suggested_code: string|null, confidence: string, alternative_code: string|null, samples: list<string|null>}>, attribute_catalogue: list<array{code: string, labels: array<string, string>}>}
     */
    public function suggestMappings(array $headers, array $sampleRows): array;
}
