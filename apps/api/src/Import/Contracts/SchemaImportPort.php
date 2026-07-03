<?php

declare(strict_types=1);

namespace App\Import\Contracts;

use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P5-01 (#1970) — commit an APPROVED schema batch (UC1 "IdoSell
 * schema in 5 minutes"): accepted pending rows of type=schema carry
 * structural-import cells (the same header=>value shape the CSV/XLSX
 * structural import consumes), and the adapter replays them through
 * the EXISTING structural creators (AttributeGroupImportCreator /
 * AttributeImportCreator -> Catalog CQRS commands), groups before
 * attributes, under the per-tenant BulkOperationLock, atomically.
 *
 * Idempotency mirrors the value path: the accept transition only moves
 * status=pending rows, so a second commit finds nothing and no-ops
 * (committed=false). Schema runs have no BulkSession — the pending
 * batch id itself is the operation handle (P5-04 rollback reads the
 * created codes back from the batch).
 */
interface SchemaImportPort
{
    public function commitSchemaBatch(Uuid $batchId, Uuid $approvedBy): SchemaCommitResult;
}
