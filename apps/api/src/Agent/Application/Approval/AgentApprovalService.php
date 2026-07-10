<?php

declare(strict_types=1);

namespace App\Agent\Application\Approval;

use App\Agent\Application\Run\AgentProgressPublisher;
use App\Agent\Domain\AgentRunStatus;
use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Domain\Exception\ApprovalConflictException;
use App\Catalog\Contracts\Command\BulkRollbackPort;
use App\Catalog\Contracts\Command\PendingBatchCommitPort;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use App\Identity\Contracts\Audit\AgentActionAuditor;
use App\Import\Contracts\SchemaImportPort;
use App\Shared\Application\TenantContext;
use App\Shared\Contracts\Event\AgentRunCompleted;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\RlsTenantGuard;
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
        private BulkRollbackPort $rollbacks,
        private PendingChangesPort $pendingChanges,
        private SchemaImportPort $schemaCommits,
        private AgentProgressPublisher $publisher,
        private AgentActionAuditor $auditor,
        private \Symfony\Component\Messenger\MessageBusInterface $eventBus,
        private TenantContext $tenantContext,
        private RlsTenantGuard $rlsTenantGuard,
    ) {
    }

    /**
     * #2156 — re-assert the tenant RLS GUC before an approval-state write.
     * Under FrankenPHP worker mode the DBAL connection can silently
     * reconnect mid-request, dropping the session-scoped app.current_tenant
     * that RlsContextListener set on kernel.request; the terminal-status
     * UPDATE would then hit 0 rows under FORCE RLS and the run would look
     * stuck (a reject/cancel that reported success but never persisted).
     */
    private function reassertRls(): void
    {
        $tenant = $this->tenantContext->get();
        if ($tenant instanceof Tenant) {
            $this->rlsTenantGuard->reassert($tenant);
        }
    }

    /**
     * AGENT-P8-04 (#1986) — terminal outcomes fan out to tenant webhooks
     * through the core delivery infra (Shared event, best-effort).
     */
    private function announceCompletion(AgentRun $run): void
    {
        try {
            // The fan-out stamps WebhookDelivery rows with the context
            // tenant - re-attach a managed one (earlier port calls may
            // have cleared the EM and detached it).
            $tenant = $run->getTenant();
            if ($tenant instanceof Tenant) {
                $managed = $this->entityManager->find(Tenant::class, $tenant->getId()->toRfc4122());
                if ($managed instanceof Tenant) {
                    $this->tenantContext->set($managed);
                }
            }

            $this->eventBus->dispatch(new AgentRunCompleted(
                $run->getId(),
                $run->getIntent(),
                $run->getStatus()->value,
            ));
        } catch (Throwable) {
            // Webhook fan-out is best-effort; the decision itself already
            // committed and is audited.
        }
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

        $isSchemaBatch = $this->isSchemaBatch($batchId);

        $this->reassertRls();
        $run->markCommitting($approvedBy);
        $this->entityManager->flush();
        $this->publisher->status($run);

        try {
            if ($isSchemaBatch) {
                // AGENT-P5-01 (#1970) — schema batches replay through the
                // structural import port; the batch id is the operation
                // handle (no BulkSession - P5-04 owns schema rollback).
                $schemaResult = $this->schemaCommits->commitSchemaBatch($batchId, $approvedBy);
                $result = new \App\Catalog\Contracts\Command\PendingBatchCommitResult(
                    bulkSessionId: $schemaResult->committed ? $batchId : null,
                    committedValues: $schemaResult->created + $schemaResult->updated,
                    objectsTouched: $schemaResult->created + $schemaResult->updated,
                    issues: array_map(static fn (string $m): array => ['objectId' => '', 'attributeCode' => '', 'message' => $m], $schemaResult->messages),
                );
            } else {
                // AICG-P3-02 (#2335) — content batches carry the audit trail
                // (source_attributes + recipe_id, jsonb-schemas §5) in the
                // pending row meta; fold it into the batch provenance stamp.
                $result = $this->commits->commitAcceptedBatch($batchId, $approvedBy, $this->contentMetaFromBatch($batchId) + [
                    'agent_run_id' => $run->getId()->toRfc4122(),
                    'model' => $run->getModel(),
                    'intent' => $run->getIntent(),
                ]);
            }
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

        // The commit above did heavy DB work — most likely to have triggered
        // a reconnect, so re-assert before the final terminal write.
        $this->reassertRls();
        $run->markDone($result->bulkSessionId);
        $this->entityManager->flush();
        $this->publisher->status($run);

        // AGENT-P3-07 (#1967) — accountability: who approved, what the
        // agent committed, with which model, at what cost (PRD §10.5).
        $this->auditor->recordAgentAction('agent_batch_committed', $run->getId()->toRfc4122(), $run->getUserId()->toRfc4122(), [
            'approved_by' => $approvedBy->toRfc4122(),
            'pending_change_batch_id' => $batchId->toRfc4122(),
            'bulk_operation_id' => $result->bulkSessionId->toRfc4122(),
            'committed_values' => $result->committedValues,
            'objects_touched' => $result->objectsTouched,
            'model' => $run->getModel(),
            'tokens_input' => $run->getTokensInput(),
            'tokens_output' => $run->getTokensOutput(),
            'cost_usd' => $run->getCostUsd(),
        ]);
        $this->announceCompletion($run);

        return $run;
    }

    /**
     * AGENT-P3-03 (#1963) — the clean "no": every pending proposal of the
     * run's batch transitions to rejected and the run lands on rejected.
     * The catalog is untouched by construction — nothing ever reached it
     * before an approve. Idempotent: a second reject returns as-is.
     */
    public function reject(Uuid $runId): AgentRun
    {
        $run = $this->load($runId);

        if (AgentRunStatus::Rejected === $run->getStatus()) {
            return $run;
        }

        if (AgentRunStatus::AwaitingApproval !== $run->getStatus()) {
            throw ApprovalConflictException::cannotReject($run->getStatus()->value);
        }

        $batchId = $run->getPendingChangeBatchId();
        if ($batchId instanceof Uuid) {
            $this->pendingChanges->reject($batchId);
        }

        $this->reassertRls();
        $run->markRejected();
        $this->entityManager->flush();
        $this->publisher->status($run);

        $this->auditor->recordAgentAction('agent_run_rejected', $run->getId()->toRfc4122(), $run->getUserId()->toRfc4122(), [
            'pending_change_batch_id' => $batchId?->toRfc4122(),
        ]);
        // LAST: an inline (sync-transport) delivery failure rolls back and
        // closes the EM - nothing may need it afterwards.
        $this->announceCompletion($run);

        return $run;
    }

    /**
     * AGENT-P3-03 (#1963) — interrupt a run mid-flight (planning /
     * awaiting_input / awaiting_approval). A materialized batch expires so
     * no dead proposals linger; committing cannot be cancelled (the commit
     * is a single atomic transaction — it either fully lands or fully
     * rolls back). Idempotent: a second cancel returns as-is.
     */
    public function cancel(Uuid $runId): AgentRun
    {
        $run = $this->load($runId);

        if (AgentRunStatus::Cancelled === $run->getStatus()) {
            return $run;
        }

        if (AgentRunStatus::Committing === $run->getStatus() || $run->getStatus()->isTerminal()) {
            throw ApprovalConflictException::cannotCancel($run->getStatus()->value);
        }

        $batchId = $run->getPendingChangeBatchId();
        if ($batchId instanceof Uuid) {
            $this->pendingChanges->expire($batchId);
        }

        $this->reassertRls();
        $run->markCancelled();
        $this->entityManager->flush();
        $this->publisher->status($run);

        $this->auditor->recordAgentAction('agent_run_cancelled', $run->getId()->toRfc4122(), $run->getUserId()->toRfc4122(), [
            'pending_change_batch_id' => $batchId?->toRfc4122(),
        ]);
        $this->announceCompletion($run);

        return $run;
    }

    /**
     * AGENT-P3-04 (#1964) — "Cofnij tę operację": replay the undo-log of
     * the run's BulkSession on the canonical object_values (superseded
     * rows are skipped so later manual edits survive) and mark the run
     * rolled_back. Idempotent: a second rollback returns as-is.
     */
    public function rollback(Uuid $runId): AgentRun
    {
        $run = $this->load($runId);

        if (AgentRunStatus::RolledBack === $run->getStatus()) {
            return $run;
        }

        $bulkOperationId = $run->getBulkOperationId();
        if (AgentRunStatus::Done !== $run->getStatus() || !$bulkOperationId instanceof Uuid) {
            throw ApprovalConflictException::cannotRollback($run->getStatus()->value);
        }

        $batchId = $run->getPendingChangeBatchId();
        if ($batchId instanceof Uuid && $this->isSchemaBatch($batchId)) {
            // AGENT-P5-04 (#1973) — dataless-only boundary: delete what the
            // batch CREATED, and only when no data/attachments exist; any
            // offender blocks the WHOLE rollback with reasons.
            $schemaResult = $this->schemaCommits->rollbackSchemaBatch($batchId);
            if (!$schemaResult->performed) {
                $reasons = implode(' ', array_map(static fn (array $b): string => $b['reason'], $schemaResult->blocked));

                throw ApprovalConflictException::cannotRollback('schema: '.$reasons);
            }
        } else {
            $this->rollbacks->rollbackSession($bulkOperationId);
        }

        // The rollback handler's per-chunk clear() detached the run.
        $run = $this->reload($runId);
        $this->reassertRls();
        $run->markRolledBack();
        $this->entityManager->flush();
        $this->publisher->status($run);

        $this->auditor->recordAgentAction('agent_batch_rolled_back', $run->getId()->toRfc4122(), $run->getUserId()->toRfc4122(), [
            'bulk_operation_id' => $bulkOperationId->toRfc4122(),
            'model' => $run->getModel(),
        ]);
        $this->announceCompletion($run);

        return $run;
    }

    private function load(Uuid $runId): AgentRun
    {
        $run = $this->entityManager->find(AgentRun::class, $runId);
        if (!$run instanceof AgentRun) {
            throw new LogicException(\sprintf('Agent run "%s" not found.', $runId->toRfc4122()));
        }

        return $run;
    }

    /**
     * Peek the batch kind: materializers emit homogeneous batches, so
     * the first row decides the commit path.
     */
    private function isSchemaBatch(Uuid $batchId): bool
    {
        foreach ($this->pendingChanges->iterateBatch($batchId) as $view) {
            return PendingChangeType::Schema === $view->changeType;
        }

        return false;
    }

    private function reload(Uuid $runId): AgentRun
    {
        $run = $this->entityManager->find(AgentRun::class, $runId);
        if (!$run instanceof AgentRun) {
            throw new LogicException(\sprintf('Agent run "%s" vanished mid-approval.', $runId->toRfc4122()));
        }

        return $run;
    }

    /**
     * AICG-P3-02 (#2335) — the content audit keys of the batch's first
     * row (content tools write one homogeneous batch per run; bulk-edit
     * rows carry no such meta, so this is a no-op for them).
     *
     * @return array<string, mixed>
     */
    private function contentMetaFromBatch(Uuid $batchId): array
    {
        foreach ($this->pendingChanges->iterateBatch($batchId) as $view) {
            $meta = $view->meta ?? [];
            $content = [];
            if (\is_array($meta['source_attributes'] ?? null)) {
                $content['source_attributes'] = $meta['source_attributes'];
            }
            if (\is_string($meta['recipe_id'] ?? null)) {
                $content['recipe_id'] = $meta['recipe_id'];
            }

            return $content;
        }

        return [];
    }
}
