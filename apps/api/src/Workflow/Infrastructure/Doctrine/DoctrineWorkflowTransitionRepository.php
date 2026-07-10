<?php

declare(strict_types=1);

namespace App\Workflow\Infrastructure\Doctrine;

use App\Workflow\Contracts\TransitionLogEntry;
use App\Workflow\Contracts\TransitionLogPort;
use App\Workflow\Domain\Entity\WorkflowTransition;
use App\Workflow\Domain\Repository\WorkflowTransitionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * WFL-P0-04 (#2413) — Doctrine adapter for the transition log. Tenant
 * isolation is layered: the Doctrine TenantFilter scopes every query
 * below, and Postgres RLS on `workflow_transitions` backstops raw
 * access (Version20260711090000).
 */
final readonly class DoctrineWorkflowTransitionRepository implements TransitionLogPort, WorkflowTransitionRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function add(WorkflowTransition $transition): void
    {
        $this->entityManager->persist($transition);
    }

    public function pageForObject(Uuid $objectId, int $limit, ?Uuid $before = null): array
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(WorkflowTransition::class, 't')
            ->where('t.objectId = :objectId')
            ->setParameter('objectId', $objectId)
            ->orderBy('t.id', 'DESC')
            ->setMaxResults($limit);

        if (null !== $before) {
            $builder->andWhere('t.id < :before')->setParameter('before', $before);
        }

        /** @var list<WorkflowTransition> $rows */
        $rows = $builder->getQuery()->getResult();

        return \array_map($this->toEntry(...), $rows);
    }

    public function latestForObject(Uuid $objectId, string $transition): ?TransitionLogEntry
    {
        /** @var list<WorkflowTransition> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(WorkflowTransition::class, 't')
            ->where('t.objectId = :objectId')
            ->andWhere('t.transition = :transition')
            ->setParameter('objectId', $objectId)
            ->setParameter('transition', $transition)
            ->orderBy('t.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return [] === $rows ? null : $this->toEntry($rows[0]);
    }

    private function toEntry(WorkflowTransition $transition): TransitionLogEntry
    {
        return new TransitionLogEntry(
            id: $transition->getId(),
            objectId: $transition->getObjectId(),
            workflowName: $transition->getWorkflowName(),
            transition: $transition->getTransition(),
            fromPlace: $transition->getFromPlace(),
            toPlace: $transition->getToPlace(),
            actorUserId: $transition->getActorUserId(),
            comment: $transition->getComment(),
            context: $transition->getContext(),
            createdAt: $transition->getCreatedAt(),
        );
    }
}
