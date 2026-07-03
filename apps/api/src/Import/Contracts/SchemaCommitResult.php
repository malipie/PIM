<?php

declare(strict_types=1);

namespace App\Import\Contracts;

/**
 * AGENT-P5-01 (#1970) — outcome of a schema batch commit. committed =
 * false means the batch had no pending rows to accept (already decided
 * or expired) and nothing changed.
 */
final readonly class SchemaCommitResult
{
    /**
     * @param list<string> $messages per-row error/warning messages (operator-facing)
     */
    public function __construct(
        public bool $committed,
        public int $created,
        public int $updated,
        public int $failed,
        public array $messages,
    ) {
    }

    public static function nothingToCommit(): self
    {
        return new self(committed: false, created: 0, updated: 0, failed: 0, messages: []);
    }
}
