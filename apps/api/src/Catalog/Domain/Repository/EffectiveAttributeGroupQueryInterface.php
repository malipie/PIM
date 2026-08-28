<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\AttributeGroupAttribute;
use App\Catalog\Domain\Entity\CategoryAttributeGroup;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttributeGroup;
use Symfony\Component\Uid\Uuid;

/**
 * Read port for the persistence-backed parts of effective group resolution.
 */
interface EffectiveAttributeGroupQueryInterface
{
    /** @return list<ObjectTypeAttributeGroup> */
    public function findObjectTypeGroups(ObjectType $type): array;

    /**
     * @param list<Uuid> $categoryIds ordered root to leaf
     *
     * @return list<CategoryAttributeGroup>
     */
    public function findCategoryGroups(array $categoryIds, ObjectType $targetType): array;

    /**
     * @param list<AttributeGroup> $groups
     *
     * @return list<AttributeGroupAttribute>
     */
    public function findGroupAttributes(array $groups): array;
}
