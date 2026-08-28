<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;

interface IdentifierDuplicateQueryInterface
{
    public function isDuplicate(CatalogObject $object, Attribute $attribute, string $value): bool;
}
