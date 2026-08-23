<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Command;

use Symfony\Component\Uid\Uuid;

interface CreateObjectProposalPort
{
    /**
     * @param array<string, mixed> $attributes
     * @param list<string>         $categoryIds
     */
    public function materializeObjectCreation(
        Uuid $batchId,
        Uuid $userId,
        string $objectTypeCode,
        string $code,
        array $attributes,
        ?string $parentId = null,
        array $categoryIds = [],
    ): CreateObjectProposal;
}
