<?php

declare(strict_types=1);

namespace App\Workflow\Infrastructure\Doctrine;

use App\Workflow\Contracts\WorkflowTaskStatus;
use App\Workflow\Contracts\WorkflowTaskType;
use App\Workflow\Domain\Entity\WorkflowTask;
use App\Workflow\Domain\Repository\WorkflowTaskRepositoryInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * WFL-P4-01 (#2428) — Doctrine adapter. "Mine" resolves the caller's
 * role codes with raw DBAL over BOTH coexisting assignment paths
 * (legacy `user_roles` + RBAC `user_role_assignments`, consolidation
 * #644) — referencing Identity entities in DQL would leak
 * Identity_Internals into this BC (deptrac).
 */
final readonly class DoctrineWorkflowTaskRepository implements WorkflowTaskRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(WorkflowTask $task): void
    {
        $this->entityManager->persist($task);
        $this->entityManager->flush();
    }

    public function find(Uuid $id): ?WorkflowTask
    {
        return $this->entityManager->find(WorkflowTask::class, $id);
    }

    public function page(
        int $limit,
        ?Uuid $before = null,
        ?WorkflowTaskStatus $status = null,
        ?Uuid $objectId = null,
        ?Uuid $mineUserId = null,
        ?DateTimeImmutable $dueBefore = null,
        array $mineExtraRoleCodes = [],
    ): array {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(WorkflowTask::class, 't')
            ->orderBy('t.id', 'DESC')
            ->setMaxResults($limit);

        if (null !== $before) {
            $builder->andWhere('t.id < :before')->setParameter('before', $before);
        }
        if (null !== $status) {
            $builder->andWhere('t.status = :status')->setParameter('status', $status);
        }
        if (null !== $objectId) {
            $builder->andWhere('t.objectId = :objectId')->setParameter('objectId', $objectId);
        }
        if (null !== $dueBefore) {
            $builder->andWhere('t.dueDate IS NOT NULL AND t.dueDate <= :dueBefore')
                ->setParameter('dueBefore', $dueBefore);
        }
        if (null !== $mineUserId) {
            $roleCodes = array_values(array_unique(
                [...$this->roleCodesOf($mineUserId), ...$mineExtraRoleCodes],
            ));
            if ([] === $roleCodes) {
                $builder->andWhere('t.assigneeUserId = :me')->setParameter('me', $mineUserId);
            } else {
                $builder->andWhere('t.assigneeUserId = :me OR t.assigneeRoleCode IN (:roles)')
                    ->setParameter('me', $mineUserId)
                    ->setParameter('roles', $roleCodes);
            }
        }

        /** @var list<WorkflowTask> $rows */
        $rows = $builder->getQuery()->getResult();

        return $rows;
    }

    public function openTasksFor(Uuid $objectId, WorkflowTaskType $type): array
    {
        /** @var list<WorkflowTask> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(WorkflowTask::class, 't')
            ->where('t.objectId = :objectId')
            ->andWhere('t.type = :type')
            ->andWhere('t.status = :open')
            ->setParameter('objectId', $objectId)
            ->setParameter('type', $type)
            ->setParameter('open', WorkflowTaskStatus::Open)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<string>
     */
    public function roleCodesOf(Uuid $userId): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT r.code FROM roles r
            JOIN user_roles ur ON ur.role_id = r.id AND ur.user_id = :user
            UNION
            SELECT DISTINCT r.code FROM roles r
            JOIN user_role_assignments ura ON ura.role_id = r.id AND ura.user_id = :user
            SQL;

        /** @var list<string> $codes */
        $codes = $this->entityManager->getConnection()
            ->fetchFirstColumn($sql, ['user' => $userId->toRfc4122()]);

        return $codes;
    }
}
