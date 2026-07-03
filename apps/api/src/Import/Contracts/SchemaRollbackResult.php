<?php

declare(strict_types=1);

namespace App\Import\Contracts;

/**
 * AGENT-P5-04 (#1973) — outcome of a schema-batch rollback. performed =
 * false means the boundary blocked it (a created attribute already
 * carries data, or a created group still has foreign attachments) and
 * NOTHING was deleted — the operator decides: keep it, or clear the
 * data manually first.
 */
final readonly class SchemaRollbackResult
{
    /**
     * @param list<string>                              $removedAttributes attribute codes deleted
     * @param list<string>                              $removedGroups     group codes deleted
     * @param list<array{code: string, reason: string}> $blocked
     */
    public function __construct(
        public bool $performed,
        public array $removedAttributes,
        public array $removedGroups,
        public array $blocked,
    ) {
    }
}
