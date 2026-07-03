<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Command;

use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P3-01 (#1961) — write THROUGH APPROVAL: instead of committing,
 * materialize before->after diffs into pending_changes (P0-03). The
 * materialized batch IS the plan shown to the operator (ADR-0024 c);
 * the commit through the real bulk-path happens post-accept (P3-02).
 *
 * Validation runs at materialization time with the SAME per-attribute
 * validators as manual edits; invalid proposals are rejected and
 * reported, never silently materialized. Per-attribute RBAC (by user
 * id) is enforced here as well — a code outside the user's edit scope
 * yields zero drafts for that code and a rejection entry.
 */
interface BulkEditValuesPort
{
    /**
     * @param array<string, mixed> $filterDsl selector ([] = every object of the type)
     * @param array<string, mixed> $changes   attribute code => raw value
     * @param string               $mode      overwrite|only_empty - runtime-validated (a literal
     *                                        union here makes the adapter's defensive guard
     *                                        "always true" for PHPStan)
     *
     * @throws InvalidArgumentException on unknown object type / attribute / invalid DSL
     */
    public function materializeValueEdits(
        Uuid $batchId,
        Uuid $userId,
        string $objectTypeCode,
        array $filterDsl,
        array $changes,
        string $mode,
    ): ValueEditProposal;

    /**
     * Materialize an ARITHMETIC edit (the manual `increment_numeric` bulk
     * action, exposed to the agent): apply `operator`+`operand` to a
     * numeric attribute across the selector, computing the after-value per
     * object from its current value. Non-numeric current values and
     * division-by-zero are skipped, never errored. Same approval path: the
     * result is a pending_changes batch, committed post-accept.
     *
     * @param array<string, mixed> $filterDsl selector ([] = every object of the type)
     * @param string               $operator  one of + - * / % (runtime-validated)
     *
     * @throws InvalidArgumentException on unknown object type / unsupported operator / invalid DSL
     */
    public function materializeArithmeticEdits(
        Uuid $batchId,
        Uuid $userId,
        string $objectTypeCode,
        array $filterDsl,
        string $attrCode,
        string $operator,
        float $operand,
    ): ValueEditProposal;
}
