<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroupAttribute;
use App\Catalog\Domain\Entity\CategoryAttributeGroup;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\Repository\AttributeCodeConflictQueryInterface;
use App\Catalog\Domain\Validator\AttributeCodeConflict;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineAttributeCodeConflictQuery implements AttributeCodeConflictQueryInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function findBaseConflict(
        string $code,
        ObjectType $objectType,
        ?Attribute $excludeAttribute = null,
    ): ?AttributeCodeConflict {
        $excludeId = $excludeAttribute?->getId()->toRfc4122();

        /** @var ObjectTypeAttribute|null $direct */
        $direct = $this->em
            ->createQuery(
                'SELECT j, a FROM '.ObjectTypeAttribute::class.' j'
                .' JOIN j.attribute a'
                .' WHERE j.objectType = :type AND a.code = :code'
                .(null !== $excludeId ? ' AND a.id <> :excludeId' : '')
            )
            ->setParameters(
                null !== $excludeId
                    ? ['type' => $objectType, 'code' => $code, 'excludeId' => $excludeId]
                    : ['type' => $objectType, 'code' => $code],
            )
            ->setMaxResults(1)
            ->getOneOrNullResult();

        if (null === $direct) {
            return null;
        }

        return new AttributeCodeConflict(
            code: $code,
            existingLocation: 'base',
            conflictingAttributeId: $direct->getAttribute()->getId(),
        );
    }

    public function findCategoryConflict(
        string $code,
        ObjectType $objectType,
        ?Attribute $excludeAttribute = null,
    ): ?AttributeCodeConflict {
        $excludeId = $excludeAttribute?->getId()->toRfc4122();

        /** @var list<array{a_id: mixed, category_id: mixed}> $rows */
        $rows = $this->em
            ->createQuery(
                'SELECT a.id AS a_id, cag.categoryObjectId AS category_id'
                .' FROM '.CategoryAttributeGroup::class.' cag'
                .' JOIN '.AttributeGroupAttribute::class.' aga'
                .' WITH aga.attributeGroup = cag.attributeGroup'
                .' JOIN aga.attribute a'
                .' WHERE cag.targetObjectType = :type AND a.code = :code'
                .(null !== $excludeId ? ' AND a.id <> :excludeId' : '')
            )
            ->setParameters(
                null !== $excludeId
                    ? ['type' => $objectType, 'code' => $code, 'excludeId' => $excludeId]
                    : ['type' => $objectType, 'code' => $code],
            )
            ->setMaxResults(1)
            ->getArrayResult();

        if ([] === $rows) {
            return null;
        }

        return new AttributeCodeConflict(
            code: $code,
            existingLocation: 'category:'.$this->toUuidString($rows[0]['category_id']),
            conflictingAttributeId: Uuid::fromString($this->toUuidString($rows[0]['a_id'])),
        );
    }

    private function toUuidString(mixed $raw): string
    {
        if ($raw instanceof Uuid) {
            return $raw->toRfc4122();
        }
        if (\is_string($raw)) {
            return $raw;
        }

        throw new LogicException('Expected Uuid or string from Doctrine array result, got '.\get_debug_type($raw).'.');
    }
}
