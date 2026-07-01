<?php

declare(strict_types=1);

namespace App\Export\Feed\Infrastructure\Doctrine\Repository;

use App\Export\Feed\Domain\Entity\FeedRun;
use App\Export\Feed\Domain\Enum\FeedRunStatus;
use App\Export\Feed\Domain\Repository\FeedRunRepositoryInterface;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use DateTimeZone;
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

    /**
     * @return list<FeedRun>
     */
    public function findPage(?Uuid $feedProfileId, ?string $health, ?Uuid $cursor, int $limit): array
    {
        // UUIDv7 ids are time-ordered, so id DESC == started_at DESC and the
        // keyset cursor is a plain id comparison (stable under concurrent
        // inserts, unlike OFFSET).
        $qb = $this->createQueryBuilder('r')
            ->orderBy('r.id', 'DESC')
            ->setMaxResults($limit);

        if (null !== $feedProfileId) {
            $qb->andWhere('r.feedProfileId = :fid')
                ->setParameter('fid', $feedProfileId->toRfc4122());
        }
        if (null !== $cursor) {
            $qb->andWhere('r.id < :cursor')
                ->setParameter('cursor', $cursor->toRfc4122());
        }

        // Display-status filter: `success`/`warning` split `done` by warning
        // count (the monitor's chips); `error` is the raw error status.
        match ($health) {
            'success' => $qb->andWhere('r.status = :st')->andWhere('r.warningCount = 0')
                ->setParameter('st', FeedRunStatus::Done->value),
            'warning' => $qb->andWhere('r.status = :st')->andWhere('r.warningCount > 0')
                ->setParameter('st', FeedRunStatus::Done->value),
            'error' => $qb->andWhere('r.status = :st')
                ->setParameter('st', FeedRunStatus::Error->value),
            default => null,
        };

        /** @var list<FeedRun> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    public function kpi24h(Tenant $tenant, DateTimeImmutable $now): array
    {
        $since = $now->setTimezone(new DateTimeZone('UTC'))->modify('-24 hours')->format('Y-m-d H:i:s');
        $params = ['tenant' => $tenant->getId()->toRfc4122(), 'since' => $since];

        // tenant-safe: explicit tenant_id filter (monitor KPI scoped to the authenticated tenant; RLS GUC active as defence in depth)
        $row = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT COUNT(*) AS regenerations,'
            .' COALESCE(SUM(skipped_count), 0) AS skipped,'
            ." COUNT(*) FILTER (WHERE status = 'error') AS errors"
            .' FROM feed_runs WHERE tenant_id = :tenant AND started_at >= :since',
            $params,
        )->fetchAssociative();

        // tenant-safe: explicit tenant_id filter (single most-recent error row for the monitor tile)
        $lastError = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT id, feed_profile_id, error_message FROM feed_runs'
            ." WHERE tenant_id = :tenant AND status = 'error' AND started_at >= :since"
            .' ORDER BY id DESC LIMIT 1',
            $params,
        )->fetchAssociative();

        $counters = \is_array($row) ? $row : [];

        $lastErrorOut = null;
        if (\is_array($lastError)
            && \is_string($lastError['id'] ?? null)
            && \is_string($lastError['feed_profile_id'] ?? null)
        ) {
            $lastErrorOut = [
                'run_id' => $lastError['id'],
                'feed_id' => $lastError['feed_profile_id'],
                'message' => \is_string($lastError['error_message'] ?? null) ? $lastError['error_message'] : null,
            ];
        }

        return [
            'regenerations_24h' => self::toInt($counters['regenerations'] ?? 0),
            'skipped_24h' => self::toInt($counters['skipped'] ?? 0),
            'errors_24h' => self::toInt($counters['errors'] ?? 0),
            'last_error' => $lastErrorOut,
        ];
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
