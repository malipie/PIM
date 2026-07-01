<?php

declare(strict_types=1);

namespace App\Export\Feed\Infrastructure\Doctrine\Repository;

use App\Export\Feed\Domain\Entity\FeedRunLog;
use App\Export\Feed\Domain\Repository\FeedRunLogRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<FeedRunLog>
 */
class DoctrineFeedRunLogRepository extends ServiceEntityRepository implements FeedRunLogRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeedRunLog::class);
    }

    public function save(FeedRunLog $log): void
    {
        $em = $this->getEntityManager();
        $em->persist($log);
        $em->flush();
    }

    /**
     * @return list<FeedRunLog>
     */
    public function findByRun(Uuid $feedRunId): array
    {
        $qb = $this->createQueryBuilder('l')
            ->where('l.feedRunId = :rid')
            ->orderBy('l.createdAt', 'ASC')
            ->setParameter('rid', $feedRunId->toRfc4122());

        /** @var list<FeedRunLog> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}
