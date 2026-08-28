<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Validator\AttributeCodeConflict;

/**
 * Persistence-independent lookup used by the effective-model validator.
 */
interface AttributeCodeConflictQueryInterface
{
    public function findBaseConflict(
        string $code,
        ObjectType $objectType,
        ?Attribute $excludeAttribute = null,
    ): ?AttributeCodeConflict;

    public function findCategoryConflict(
        string $code,
        ObjectType $objectType,
        ?Attribute $excludeAttribute = null,
    ): ?AttributeCodeConflict;
}
