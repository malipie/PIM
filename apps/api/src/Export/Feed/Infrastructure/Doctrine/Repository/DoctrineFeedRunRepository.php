<?php

declare(strict_types=1);

namespace App\Export\Feed\Infrastructure\Doctrine\Repository;

use App\Export\Feed\Domain\Entity\FeedRun;
use App\Export\Feed\Domain\Repository\FeedRunRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<FeedRun>
 */
class DoctrineFeedRunRepository extends ServiceEntityRepository implements FeedRunRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeedRun::class);
    }

    public function save(FeedRun $run): void
    {
        $em = $this->getEntityManager();
        $em->persist($run);
        $em->flush();
    }

    public function findById(Uuid $id): ?FeedRun
    {
        return parent::find($id->toRfc4122());
    }

    /**
     * @return list<FeedRun>
     */
    public function findByFeedProfile(Uuid $feedProfileId, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.feedProfileId = :fid')
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('fid', $feedProfileId->toRfc4122());

        /** @var list<FeedRun> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}
