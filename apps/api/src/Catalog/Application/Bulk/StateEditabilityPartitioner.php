<?php

declare(strict_types=1);

namespace App\Catalog\Application\Bulk;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Identity\Contracts\Policy\WorkflowStateEditPolicyInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * WFL-P1-02 (#2416) — splits bulk target ids into [editable, locked]
 * against the PRD §3.8 workflow-state policy before any bulk handler
 * runs. One grouped status query, no per-row hydration; the policy is
 * per-principal, so each distinct state is evaluated once. Extracted
 * from BulkActionsController (WFL-P1-05 pushed it past the 800-line
 * guard — and the DQL never belonged in a controller anyway).
 */
final readonly class StateEditabilityPartitioner
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkflowStateEditPolicyInterface $statePolicy,
    ) {
    }

    /**
     * @param list<string> $targetIds
     *
     * @return array{0: list<string>, 1: list<string>} [editable, locked]
     */
    public function partition(array $targetIds): array
    {
        /** @var list<array{id: Uuid, status: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('o.id AS id', 'o.status AS status')
            ->from(CatalogObject::class, 'o')
            ->where('o.id IN (:ids)')
            ->setParameter('ids', $targetIds)
            ->getQuery()
            ->getArrayResult();

        $editableByState = [];
        $editable = [];
        $locked = [];
        foreach ($rows as $row) {
            $state = $row['status'];
            $editableByState[$state] ??= $this->statePolicy->canEditInState($state);
            if ($editableByState[$state]) {
                $editable[] = $row['id']->toRfc4122();
            } else {
                $locked[] = $row['id']->toRfc4122();
            }
        }

        return [$editable, $locked];
    }
}
