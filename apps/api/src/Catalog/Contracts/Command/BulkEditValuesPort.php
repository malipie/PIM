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
}
