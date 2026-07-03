<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Command;

use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P3-05 (#1965) — outcome of a category-assignment
 * materialization: how many objects await approval and which category
 * ids were rejected (unknown / not a category).
 */
final readonly class CategoryAssignProposal
{
    /**
     * @param list<array{id: string, reason: string}> $rejected
     */
    public function __construct(
        public Uuid $batchId,
        public int $affectedObjects,
        public array $rejected,
    ) {
    }
}
