<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Command;

use Symfony\Component\Uid\Uuid;

interface SetStatusProposalPort
{
    /**
     * @param array<string, mixed> $filterDsl
     * @param list<mixed>|null     $selectedIds
     */
    public function materializeStatusTransition(
        Uuid $batchId,
        Uuid $userId,
        string $objectTypeCode,
        array $filterDsl,
        string $transition,
        ?array $selectedIds = null,
    ): SetStatusProposal;
}
