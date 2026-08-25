<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Service;

use App\Catalog\Contracts\Query\AttributeGroupSummary;
use Symfony\Component\Uid\Uuid;

interface AttributeGroupCatalogReader
{
    /** @return list<AttributeGroupSummary> */
    public function findAllByTenant(Uuid $tenantId): array;
}
