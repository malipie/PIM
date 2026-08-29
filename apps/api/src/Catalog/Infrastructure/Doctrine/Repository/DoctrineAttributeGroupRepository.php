<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Repository\AttributeGroupRepositoryInterface;
use App\Shared\Domain\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AttributeGroup>
 */
class DoctrineAttributeGroupRepository extends ServiceEntityRepository implements AttributeGroupRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AttributeGroup::class);
    }

    public function findByCode(string $code, Tenant $tenant): ?AttributeGroup
    {
        return $this->findOneBy(['code' => $code, 'tenant' => $tenant]);
    }

    public function findByCodes(array $codes, Tenant $tenant): array
    {
        if ([] === $codes) {
            return [];
        }

        /** @var list<AttributeGroup> $groups */
        $groups = $this->createQueryBuilder('g')
            ->andWhere('g.tenant = :tenant')
            ->andWhere('g.code IN (:codes)')
            ->setParameter('tenant', $tenant)
            ->setParameter('codes', $codes)
            ->orderBy('g.code', 'ASC')
            ->getQuery()
            ->getResult();

        $byCode = [];
        foreach ($groups as $group) {
            $byCode[$group->getCode()] = $group;
        }

        return $byCode;
    }

    public function findAllByTenant(Tenant $tenant): array
    {
        /** @var list<AttributeGroup> $result */
        $result = $this->findBy(['tenant' => $tenant], ['position' => 'ASC', 'code' => 'ASC']);

        return $result;
    }

    public function findById(\Symfony\Component\Uid\Uuid $id): ?AttributeGroup
    {
        return parent::find($id->toRfc4122());
    }

    public function save(AttributeGroup $entity): void
    {
        $em = $this->getEntityManager();
        $em->persist($entity);
        $em->flush();
    }

    public function remove(AttributeGroup $entity): void
    {
        $em = $this->getEntityManager();
        $em->remove($entity);
        $em->flush();
    }
}
