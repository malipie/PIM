<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Command;

use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P3-02 (#1962) — the ONLY way an approved agent proposal reaches
 * the catalog: pending rows are accepted and committed through the real
 * bulk-path (BatchValueWriter + BulkSession + bulk_logs undo capture)
 * with provenance=agent, atomically (all-or-nothing) and idempotently
 * (the accept transition only moves status=pending rows, so a second
 * call finds nothing and is a no-op).
 *
 * The returned BulkSession id is the rollback handle (P3-04) and lands
 * on agent_runs.bulk_operation_id.
 */
interface PendingBatchCommitPort
{
    /**
     * @param array<string, mixed> $provenanceMeta stamped on the written
     *                                             object_values rows (docs/api/jsonb-schemas.md §5,
     *                                             agent shape: agent_run_id / model / intent)
     */
    public function commitAcceptedBatch(Uuid $batchId, Uuid $approvedBy, array $provenanceMeta = []): PendingBatchCommitResult;
}
