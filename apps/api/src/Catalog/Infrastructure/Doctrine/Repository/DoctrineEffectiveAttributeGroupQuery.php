<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Entity\AttributeGroupAttribute;
use App\Catalog\Domain\Entity\CategoryAttributeGroup;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttributeGroup;
use App\Catalog\Domain\Repository\EffectiveAttributeGroupQueryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineEffectiveAttributeGroupQuery implements EffectiveAttributeGroupQueryInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function findObjectTypeGroups(ObjectType $type): array
    {
        /** @var list<ObjectTypeAttributeGroup> $junctions */
        $junctions = $this->em
            ->createQuery(
                'SELECT j, g FROM '.ObjectTypeAttributeGroup::class.' j'
                .' JOIN j.attributeGroup g'
                .' WHERE j.objectType = :type'
                .' ORDER BY j.position ASC, g.code ASC'
            )
            ->setParameter('type', $type)
            ->getResult();

        return $junctions;
    }

    public function findCategoryGroups(array $categoryIds, ObjectType $targetType): array
    {
        if ([] === $categoryIds) {
            return [];
        }

        /** @var list<CategoryAttributeGroup> $junctions */
        $junctions = $this->em
            ->createQuery(
                'SELECT j, g FROM '.CategoryAttributeGroup::class.' j'
                .' JOIN j.attributeGroup g'
                .' WHERE j.categoryObjectId IN (:ids)'
                .' AND j.targetObjectType = :type'
                .' ORDER BY j.position ASC, g.code ASC'
            )
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $categoryIds))
            ->setParameter('type', $targetType)
            ->getResult();

        return $junctions;
    }

    public function findGroupAttributes(array $groups): array
    {
        if ([] === $groups) {
            return [];
        }

        /** @var list<AttributeGroupAttribute> $junctions */
        $junctions = $this->em
            ->createQuery(
                'SELECT j, a, g FROM '.AttributeGroupAttribute::class.' j'
                .' JOIN j.attribute a'
                .' JOIN j.attributeGroup g'
                .' WHERE g IN (:groups)'
                .' ORDER BY j.position ASC, a.code ASC'
            )
            ->setParameter('groups', $groups)
            ->getResult();

        return $junctions;
    }
}
