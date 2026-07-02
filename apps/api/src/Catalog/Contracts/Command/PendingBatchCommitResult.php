<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Command;

use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P3-02 (#1962) — outcome of committing an accepted pending batch
 * through the bulk-path. A null bulkSessionId means NOTHING was
 * committed (the batch had no pending rows to accept — already
 * committed, rejected or expired) and the catalog is untouched.
 */
final readonly class PendingBatchCommitResult
{
    /**
     * @param list<array{objectId: string, attributeCode: string, message: string}> $issues
     */
    public function __construct(
        public ?Uuid $bulkSessionId,
        public int $committedValues,
        public int $objectsTouched,
        public array $issues,
    ) {
    }

    public static function nothingToCommit(): self
    {
        return new self(bulkSessionId: null, committedValues: 0, objectsTouched: 0, issues: []);
    }
}
