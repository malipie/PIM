<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Doctrine\Repository;

use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Entity\ImportUndoLog;
use App\Import\Domain\Repository\ImportUndoLogRepositoryInterface;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ImportUndoLog>
 *
 * tenant-safe: every raw query is keyed by import_session_id (a tenant-scoped
 * session resolved through the owner-scoped session repo) and import_undo_log
 * itself enforces RLS (tenant_isolation policy on app.current_tenant), so the
 * undo rows inherit tenant via the FK chain — no cross-tenant reach.
 */
class DoctrineImportUndoLogRepository extends ServiceEntityRepository implements ImportUndoLogRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportUndoLog::class);
    }

    public function add(ImportUndoLog $log): void
    {
        // No flush: the import handler's flushAndClear commits these with the
        // chunk's object writes in one transaction.
        $this->getEntityManager()->persist($log);
    }

    /**
     * @return list<ImportUndoLog>
     */
    public function findBySession(ImportSession $session): array
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.importSession = :session')
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->setParameter('session', $session);

        /** @var list<ImportUndoLog> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * @param list<Uuid> $objectIds
     *
     * @return list<ImportUndoLog>
     */
    public function findBySessionAndObjectIds(ImportSession $session, array $objectIds): array
    {
        if ([] === $objectIds) {
            return [];
        }

        $qb = $this->createQueryBuilder('u')
            ->where('u.importSession = :session')
            ->andWhere('u.objectId IN (:objectIds)')
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->setParameter('session', $session)
            ->setParameter('objectIds', $objectIds);

        /** @var list<ImportUndoLog> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * @return list<Uuid>
     */
    public function affectedObjectIds(ImportSession $session): array
    {
        /** @var list<array{object_id: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            // #2818 — ORDER BY makes the sequence stable across runs, which is
            // what lets a resumed rollback treat its checkpoint ("the first N
            // objects are done") as meaning the same N it meant last time.
            'SELECT DISTINCT object_id FROM import_undo_log WHERE import_session_id = :sid ORDER BY object_id',
            ['sid' => $session->getId()->toRfc4122()],
        );

        return array_map(static fn (array $row): Uuid => Uuid::fromString($row['object_id']), $rows);
    }

    /**
     * Scope keys (objectId|attributeCode|locale|channelId) of THIS session's undo
     * rows that a LATER import session has since overwritten on the same cell.
     * Rolling back would clobber the newer import, so the caller skips + reports
     * them instead. Provenance alone cannot tell two imports apart (both `import`),
     * so the undo-log's own chronology is the authority.
     *
     * @return array<string, true>
     */
    public function supersededScopeKeys(ImportSession $session): array
    {
        /** @var list<array{object_id: string, attribute_code: ?string, locale: ?string, channel_id: ?string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT DISTINCT a.object_id, a.attribute_code, a.locale, a.channel_id
                FROM import_undo_log a
                JOIN import_undo_log b
                  ON b.object_id = a.object_id
                 AND b.attribute_code IS NOT DISTINCT FROM a.attribute_code
                 AND b.locale IS NOT DISTINCT FROM a.locale
                 AND b.channel_id IS NOT DISTINCT FROM a.channel_id
                 AND b.import_session_id <> a.import_session_id
                 AND b.id > a.id
                WHERE a.import_session_id = :sid
                  AND a.attribute_code IS NOT NULL
                SQL,
            ['sid' => $session->getId()->toRfc4122()],
        );

        $keys = [];
        foreach ($rows as $row) {
            $keys[$row['object_id'].'|'.(string) $row['attribute_code'].'|'.($row['locale'] ?? '').'|'.($row['channel_id'] ?? '')] = true;
        }

        return $keys;
    }

    /**
     * @return array<string, int>
     */
    public function countByOperation(ImportSession $session): array
    {
        /** @var list<array{operation: string, c: int|string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT operation, COUNT(*) AS c FROM import_undo_log WHERE import_session_id = :sid GROUP BY operation',
            ['sid' => $session->getId()->toRfc4122()],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['operation']] = (int) $row['c'];
        }

        return $counts;
    }

    public function purgeForClosedWindows(DateTimeImmutable $now, int $limit = 5000): int
    {
        // Delete undo rows for sessions whose rollback window has closed — they
        // can no longer be rolled back, so the before-state is dead weight.
        // $limit is a controlled int (not user input), inlined to keep the
        // subquery LIMIT a literal.
        $sql = \sprintf(
            <<<'SQL'
                DELETE FROM import_undo_log
                WHERE id IN (
                    SELECT u.id FROM import_undo_log u
                    JOIN import_sessions s ON s.id = u.import_session_id
                    WHERE s.rollback_until IS NOT NULL AND s.rollback_until < :now
                    LIMIT %d
                )
                SQL,
            max(1, $limit),
        );

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            $sql,
            ['now' => $now->format('Y-m-d H:i:s')],
        );
    }
}
