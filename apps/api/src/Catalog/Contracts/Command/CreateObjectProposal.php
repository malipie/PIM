<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Command;

use Symfony\Component\Uid\Uuid;

/** Result of validating and materializing one proposed object creation. */
final readonly class CreateObjectProposal
{
    /** @param list<array{field: string, reason: string}> $rejected */
    public function __construct(
        public Uuid $batchId,
        public bool $materialized,
        public array $rejected = [],
    ) {
    }
}
