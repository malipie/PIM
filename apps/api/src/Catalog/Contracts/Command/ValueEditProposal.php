<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Command;

use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P3-01 (#1961) — outcome of a value-edit materialization: how
 * many diffs await approval and what was rejected (validation / RBAC)
 * or skipped (only_empty mode) — the numbers of the operator-facing
 * plan.
 */
final readonly class ValueEditProposal
{
    /**
     * @param list<array{code: string, reason: string}>                                                                        $rejected
     * @param list<array{object_id: string, object_code: string, attribute_code: string, current_value: array<string, mixed>}> $skippedExistingExamples
     */
    public function __construct(
        public Uuid $batchId,
        public int $affectedObjects,
        public int $materializedChanges,
        public int $skippedExisting,
        public array $rejected,
        public array $skippedExistingExamples = [],
        public int $selectorRejected = 0,
        public int $selectorMatchedObjects = 0,
        public int $permissionRejectedAttributes = 0,
        public ?string $mode = null,
    ) {
    }
}
