<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeStatus;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use App\Import\Application\Service\Structural\AttributeGroupImportCreator;
use App\Import\Application\Service\Structural\AttributeImportCreator;
use App\Import\Application\Service\Structural\StructuralImportRowResult;
use App\Import\Contracts\SchemaCommitResult;
use App\Import\Contracts\SchemaImportPort;
use App\Shared\Application\BulkOperationLock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * AGENT-P5-01 (#1970) — adapter behind Import\Contracts: replays an
 * approved schema batch through the SAME structural creators the
 * CSV/XLSX structural import uses (cell grammar, upsert-by-code,
 * option sync, Catalog CQRS commands underneath) — the agent gets zero
 * new schema machinery.
 *
 * Groups commit before attributes (attributes reference groups by
 * code), everything inside one DB transaction (a failure also rolls
 * back the accept transition, so the batch stays re-approvable) and
 * under the per-tenant BulkOperationLock, exactly like the wizard.
 */
final readonly class AgentSchemaImportApplier implements SchemaImportPort
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
        private PendingChangesPort $pendingChanges,
        private AttributeGroupImportCreator $groupCreator,
        private AttributeImportCreator $attributeCreator,
        private BulkOperationLock $bulkLock,
    ) {
    }

    public function commitSchemaBatch(Uuid $batchId, Uuid $approvedBy): SchemaCommitResult
    {
        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Cannot commit a schema batch without a current tenant.');
        }

        $lock = $this->bulkLock->acquire($tenant);
        if (null === $lock) {
            throw new ConflictHttpException('Another bulk operation is in progress for this tenant - try again shortly.');
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            if (0 === $this->pendingChanges->accept($batchId)) {
                $connection->rollBack();

                return SchemaCommitResult::nothingToCommit();
            }

            // Groups first: attribute rows attach to groups by code.
            /** @var list<array<string, string|null>> $groupRows */
            $groupRows = [];
            /** @var list<array<string, string|null>> $attributeRows */
            $attributeRows = [];
            foreach ($this->pendingChanges->iterateBatch($batchId) as $view) {
                if (PendingChangeStatus::Accepted !== $view->status
                    || PendingChangeType::Schema !== $view->changeType
                    || null === $view->after) {
                    continue;
                }
                $kind = $view->after['schema_kind'] ?? null;
                $cells = $view->after['cells'] ?? null;
                if (!\is_string($kind) || !\is_array($cells)) {
                    continue;
                }
                $clean = [];
                foreach ($cells as $header => $value) {
                    if (\is_string($header) && (\is_string($value) || null === $value)) {
                        $clean[$header] = $value;
                    }
                }
                if ('attribute_group' === $kind) {
                    $groupRows[] = $clean;
                } elseif ('attribute' === $kind) {
                    $attributeRows[] = $clean;
                }
            }

            if ([] === $groupRows && [] === $attributeRows) {
                $connection->rollBack();

                return SchemaCommitResult::nothingToCommit();
            }

            // iterateBatch() cleared the EM, detaching the Tenant held by
            // TenantContext - re-attach a managed one so the CQRS handlers
            // (TenantAssignmentListener underneath) can stamp new rows.
            $managed = $this->entityManager->find(Tenant::class, $tenant->getId()->toRfc4122());
            if ($managed instanceof Tenant) {
                $this->tenantContext->set($managed);
                $tenant = $managed;
            }

            $created = 0;
            $updated = 0;
            $failed = 0;
            $messages = [];

            $rowNumber = 0;
            foreach ($groupRows as $cells) {
                $this->applyRow($this->groupCreator->create(++$rowNumber, $cells, $tenant), $created, $updated, $failed, $messages);
            }
            foreach ($attributeRows as $cells) {
                $this->applyRow($this->attributeCreator->create(++$rowNumber, $cells, $tenant), $created, $updated, $failed, $messages);
            }

            $this->entityManager->flush();
            $connection->commit();

            return new SchemaCommitResult(
                committed: true,
                created: $created,
                updated: $updated,
                failed: $failed,
                messages: $messages,
            );
        } catch (Throwable $failure) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $failure;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param list<string> $messages
     */
    private function applyRow(StructuralImportRowResult $result, int &$created, int &$updated, int &$failed, array &$messages): void
    {
        match ($result->outcome) {
            StructuralImportRowResult::OUTCOME_CREATED => ++$created,
            StructuralImportRowResult::OUTCOME_UPDATED => ++$updated,
            StructuralImportRowResult::OUTCOME_ERROR => ++$failed,
            default => null,
        };

        foreach ($result->logs as $log) {
            $messages[] = \sprintf('%s%s: %s', $result->code ?? 'row', null !== $log['columnName'] ? ' ['.$log['columnName'].']' : '', $log['message']);
        }
    }
}
