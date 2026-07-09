<?php

declare(strict_types=1);

namespace App\Export\Catalog\Infrastructure\Doctrine\Repository;

use App\Export\Catalog\Domain\Entity\CatalogRun;
use App\Export\Catalog\Domain\Enum\CatalogRunStatus;
use App\Export\Catalog\Domain\Repository\CatalogRunRepositoryInterface;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CatalogRun>
 */
class DoctrineCatalogRunRepository extends ServiceEntityRepository implements CatalogRunRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CatalogRun::class);
    }

    public function save(CatalogRun $run): void
    {
        $em = $this->getEntityManager();
        $em->persist($run);
        $em->flush();
    }

    public function findById(Uuid $id): ?CatalogRun
    {
        return parent::find($id->toRfc4122());
    }

    /**
     * @return list<CatalogRun>
     */
    public function findByCatalogProfile(Uuid $catalogProfileId, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.catalogProfileId = :cid')
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('cid', $catalogProfileId->toRfc4122());

        /** @var list<CatalogRun> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * @return list<CatalogRun>
     */
    public function findPage(?Uuid $catalogProfileId, ?string $health, ?Uuid $cursor, int $limit): array
    {
        // UUIDv7 ids are time-ordered, so id DESC == started_at DESC and the
        // keyset cursor is a plain id comparison (stable under concurrent
        // inserts, unlike OFFSET).
        $qb = $this->createQueryBuilder('r')
            ->orderBy('r.id', 'DESC')
            ->setMaxResults($limit);

        if (null !== $catalogProfileId) {
            $qb->andWhere('r.catalogProfileId = :cid')
                ->setParameter('cid', $catalogProfileId->toRfc4122());
        }
        if (null !== $cursor) {
            $qb->andWhere('r.id < :cursor')
                ->setParameter('cursor', $cursor->toRfc4122());
        }

        // Display-status filter: `success` is a completed run (`done`); `error`
        // is the raw error status. CatalogRun has no warning count, so unlike
        // FeedRun there is no warning split of `done`.
        match ($health) {
            'success' => $qb->andWhere('r.status = :st')
                ->setParameter('st', CatalogRunStatus::Done->value),
            'error' => $qb->andWhere('r.status = :st')
                ->setParameter('st', CatalogRunStatus::Error->value),
            default => null,
        };

        /** @var list<CatalogRun> $result */
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
            .' COALESCE(SUM(item_count), 0) AS items,'
            ." COUNT(*) FILTER (WHERE status = 'error') AS errors"
            .' FROM catalog_runs WHERE tenant_id = :tenant AND started_at >= :since',
            $params,
        )->fetchAssociative();

        // tenant-safe: explicit tenant_id filter (single most-recent error row for the monitor tile)
        $lastError = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT id, catalog_profile_id, error_message FROM catalog_runs'
            ." WHERE tenant_id = :tenant AND status = 'error' AND started_at >= :since"
            .' ORDER BY id DESC LIMIT 1',
            $params,
        )->fetchAssociative();

        $counters = \is_array($row) ? $row : [];

        $lastErrorOut = null;
        if (\is_array($lastError)
            && \is_string($lastError['id'] ?? null)
            && \is_string($lastError['catalog_profile_id'] ?? null)
        ) {
            $lastErrorOut = [
                'run_id' => $lastError['id'],
                'catalog_id' => $lastError['catalog_profile_id'],
                'message' => \is_string($lastError['error_message'] ?? null) ? $lastError['error_message'] : null,
            ];
        }

        return [
            'regenerations_24h' => self::toInt($counters['regenerations'] ?? 0),
            'items_24h' => self::toInt($counters['items'] ?? 0),
            'errors_24h' => self::toInt($counters['errors'] ?? 0),
            'last_error' => $lastErrorOut,
        ];
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
