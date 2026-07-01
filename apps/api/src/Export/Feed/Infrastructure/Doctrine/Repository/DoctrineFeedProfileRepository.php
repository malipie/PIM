<?php

declare(strict_types=1);

namespace App\Export\Feed\Infrastructure\Doctrine\Repository;

use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Repository\FeedProfileRepositoryInterface;
use App\Shared\Domain\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<FeedProfile>
 */
class DoctrineFeedProfileRepository extends ServiceEntityRepository implements FeedProfileRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeedProfile::class);
    }

    public function save(FeedProfile $profile): void
    {
        $em = $this->getEntityManager();
        $em->persist($profile);
        $em->flush();
    }

    public function remove(FeedProfile $profile): void
    {
        $em = $this->getEntityManager();
        $em->remove($profile);
        $em->flush();
    }

    public function findById(Uuid $id): ?FeedProfile
    {
        return parent::find($id->toRfc4122());
    }

    public function findByTenantAndCode(Tenant $tenant, string $code): ?FeedProfile
    {
        return $this->findOneBy(['tenant' => $tenant, 'code' => $code]);
    }

    /**
     * @return list<FeedProfile>
     */
    public function findByTenant(Tenant $tenant): array
    {
        $qb = $this->createQueryBuilder('f')
            ->where('f.tenant = :tenant')
            ->orderBy('f.updatedAt', 'DESC')
            ->setParameter('tenant', $tenant);

        /** @var list<FeedProfile> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}
