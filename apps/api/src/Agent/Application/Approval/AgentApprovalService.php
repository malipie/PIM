<?php

declare(strict_types=1);

namespace App\Agent\Application\Approval;

use App\Agent\Application\Run\AgentProgressPublisher;
use App\Agent\Domain\AgentRunStatus;
use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Domain\Exception\ApprovalConflictException;
use App\Catalog\Contracts\Command\PendingBatchCommitPort;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * AGENT-P3-02 (#1962) — the human approval gate (ADR-0024 c): approve
 * flips the run awaiting_approval -> committing, commits the batch
 * through the Catalog bulk-path port (atomic, provenance=agent) and
 * lands on done with the BulkSession id as the rollback handle.
 *
 * Idempotency is layered: a run already done with a bulk_operation_id
 * returns as-is (double approve = one commit), the status guard rejects
 * every other re-entry, and the port itself no-ops when the batch has
 * no pending rows left. A commit failure rolls the catalog back inside
 * the port and marks the run error — the batch stays pending, so a
 * fixed system can be re-approved.
 */
final readonly class AgentApprovalService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $managerRegistry,
        private PendingBatchCommitPort $commits,
        private AgentProgressPublisher $publisher,
    ) {
    }

    public function approve(Uuid $runId, Uuid $approvedBy): AgentRun
    {
        $run = $this->entityManager->find(AgentRun::class, $runId);
        if (!$run instanceof AgentRun) {
            throw new LogicException(\sprintf('Agent run "%s" not found.', $runId->toRfc4122()));
        }

        if (AgentRunStatus::Done === $run->getStatus() && null !== $run->getBulkOperationId()) {
            return $run;
        }

        if (AgentRunStatus::AwaitingApproval !== $run->getStatus()) {
            throw ApprovalConflictException::wrongStatus($run->getStatus()->value);
        }

        $batchId = $run->getPendingChangeBatchId();
        if (!$batchId instanceof Uuid) {
            throw ApprovalConflictException::noBatch();
        }

        $run->markCommitting($approvedBy);
        $this->entityManager->flush();
        $this->publisher->status($run);

        try {
            $result = $this->commits->commitAcceptedBatch($batchId, $approvedBy, [
                'agent_run_id' => $run->getId()->toRfc4122(),
                'model' => $run->getModel(),
                'intent' => $run->getIntent(),
            ]);
        } catch (Throwable $failure) {
            // A DBAL failure closes the EntityManager; the error status
            // still has to land, so mark it on a fresh manager.
            $em = $this->entityManager->isOpen()
                ? $this->entityManager
                : $this->managerRegistry->resetManager();
            $failedRun = $em->find(AgentRun::class, $runId);
            if ($failedRun instanceof AgentRun) {
                $failedRun->markError('Commit failed: '.$failure->getMessage());
                $em->flush();
                $this->publisher->status($failedRun);
            }

            throw $failure;
        }

        // The committer's per-chunk clear() detached the run.
        $run = $this->reload($runId);

        if (null === $result->bulkSessionId) {
            $run->markError('Pending batch had no accepted rows to commit.');
            $this->entityManager->flush();
            $this->publisher->status($run);

            throw ApprovalConflictException::nothingToCommit();
        }

        $run->markDone($result->bulkSessionId);
        $this->entityManager->flush();
        $this->publisher->status($run);

        return $run;
    }

    private function reload(Uuid $runId): AgentRun
    {
        $run = $this->entityManager->find(AgentRun::class, $runId);
        if (!$run instanceof AgentRun) {
            throw new LogicException(\sprintf('Agent run "%s" vanished mid-approval.', $runId->toRfc4122()));
        }

        return $run;
    }
}
