<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Command;

use Symfony\Component\Uid\Uuid;

final readonly class SetStatusProposal
{
    /** @param list<array{object_id: string, object_code: string, reason: string}> $blocked */
    public function __construct(
        public Uuid $batchId,
        public int $affectedObjects,
        public int $selectorRejected,
        public array $blocked = [],
    ) {
    }
}
