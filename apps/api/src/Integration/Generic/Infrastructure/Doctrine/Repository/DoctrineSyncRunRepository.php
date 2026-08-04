<?php

declare(strict_types=1);

namespace App\Integration\Generic\Infrastructure\Doctrine\Repository;

use App\Integration\Generic\Domain\Entity\SyncBinding;
use App\Integration\Generic\Domain\Entity\SyncRun;
use App\Integration\Generic\Domain\Enum\SyncRunStatus;
use App\Integration\Generic\Domain\Repository\SyncRunRepositoryInterface;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<SyncRun>
 */
class DoctrineSyncRunRepository extends ServiceEntityRepository implements SyncRunRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SyncRun::class);
    }

    public function save(SyncRun $run): void
    {
        $em = $this->getEntityManager();
        $em->persist($run);
        $em->flush();
    }

    public function findById(Uuid $id): ?SyncRun
    {
        return parent::find($id->toRfc4122());
    }

    /**
     * @return list<SyncRun>
     */
    public function findByBinding(SyncBinding $binding): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.binding = :binding')
            ->orderBy('r.startedAt', 'DESC')
            ->setParameter('binding', $binding);

        /** @var list<SyncRun> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    public function findRunningByBinding(SyncBinding $binding, DateTimeImmutable $since): ?SyncRun
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.binding = :binding')
            ->andWhere('r.status = :status')
            ->andWhere('r.startedAt > :since')
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults(1)
            ->setParameter('binding', $binding)
            ->setParameter('status', SyncRunStatus::Running->value)
            ->setParameter('since', $since);

        /** @var SyncRun|null $result */
        $result = $qb->getQuery()->getOneOrNullResult();

        return $result;
    }
}
