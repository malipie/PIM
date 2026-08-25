<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Catalog\Application\Command\DeleteAttribute\DeleteAttributeCommand;
use App\Catalog\Application\Command\DeleteAttributeGroup\DeleteAttributeGroupCommand;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeStatus;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use App\Catalog\Contracts\Service\AttributeGroupCatalogReader;
use App\Import\Application\Service\Structural\AttributeGroupImportCreator;
use App\Import\Application\Service\Structural\AttributeImportCreator;
use App\Import\Application\Service\Structural\StructuralImportRowResult;
use App\Import\Contracts\SchemaCommitResult;
use App\Import\Contracts\SchemaImportPort;
use App\Import\Contracts\SchemaRollbackResult;
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
        private AttributeGroupCatalogReader $attributeGroups,
        private BulkOperationLock $bulkLock,
        private \Symfony\Component\Messenger\MessageBusInterface $commandBus,
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
            /** @var list<array{id: Uuid, cells: array<string, string|null>}> $groupRows */
            $groupRows = [];
            /** @var list<array{id: Uuid, cells: array<string, string|null>}> $attributeRows */
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
                    $groupRows[] = ['id' => $view->id, 'cells' => $clean];
                } elseif ('attribute' === $kind) {
                    $attributeRows[] = ['id' => $view->id, 'cells' => $clean];
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

            // Agent proposals are atomic promises, unlike best-effort file
            // imports. A missing group must abort before any attribute is
            // created; silently warning here produced a successful run with
            // an orphaned library attribute (#3014).
            $this->assertReferencedGroupsExist($groupRows, $attributeRows, $tenant);

            $created = 0;
            $updated = 0;
            $failed = 0;
            $messages = [];

            $rowNumber = 0;
            foreach ($groupRows as $row) {
                $result = $this->groupCreator->create(++$rowNumber, $row['cells'], $tenant);
                $this->applyRow($result, $created, $updated, $failed, $messages);
                // Rollback boundary bookkeeping (P5-04): only rows that
                // CREATED something may ever be deleted by a rollback.
                $this->pendingChanges->annotate($row['id'], ['schema_outcome' => $result->outcome]);
            }
            foreach ($attributeRows as $row) {
                $result = $this->attributeCreator->create(++$rowNumber, $row['cells'], $tenant);
                $this->applyRow($result, $created, $updated, $failed, $messages);
                $this->pendingChanges->annotate($row['id'], ['schema_outcome' => $result->outcome]);
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
     * @param list<array{id: Uuid, cells: array<string, string|null>}> $groupRows
     * @param list<array{id: Uuid, cells: array<string, string|null>}> $attributeRows
     */
    private function assertReferencedGroupsExist(array $groupRows, array $attributeRows, Tenant $tenant): void
    {
        $declaredCodes = [];
        foreach ($groupRows as $row) {
            $code = trim($row['cells']['code'] ?? '');
            if ('' !== $code) {
                $declaredCodes[$code] = true;
            }
        }
        $existingCodes = [];
        foreach ($this->attributeGroups->findAllByTenant($tenant->getId()) as $group) {
            $existingCodes[$group->code] = true;
        }

        foreach ($attributeRows as $row) {
            $attributeCode = trim($row['cells']['code'] ?? 'unknown');
            foreach (MultiValueSplitter::split($row['cells']['groups'] ?? '') as $groupCode) {
                if (isset($declaredCodes[$groupCode])) {
                    continue;
                }
                if (!isset($existingCodes[$groupCode])) {
                    throw new LogicException(\sprintf(
                        'Attribute "%s" references unknown attribute group code "%s". The whole schema proposal was rolled back; use an exact group code or label and retry.',
                        $attributeCode,
                        $groupCode,
                    ));
                }
            }
        }
    }

    public function rollbackSchemaBatch(Uuid $batchId): SchemaRollbackResult
    {
        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Cannot roll back a schema batch without a current tenant.');
        }
        $tenantId = $tenant->getId()->toRfc4122();

        // Only rows the commit annotated as CREATED are rollback material.
        $createdAttributes = [];
        $createdGroups = [];
        foreach ($this->pendingChanges->iterateBatch($batchId) as $view) {
            if (PendingChangeType::Schema !== $view->changeType || null === $view->after) {
                continue;
            }
            if ('created' !== ($view->meta['schema_outcome'] ?? null)) {
                continue;
            }
            $kind = $view->after['schema_kind'] ?? null;
            $code = $view->attributeCode;
            if (!\is_string($code)) {
                continue;
            }
            if ('attribute' === $kind) {
                $createdAttributes[] = $code;
            } elseif ('attribute_group' === $kind) {
                $createdGroups[] = $code;
            }
        }

        // iterateBatch() cleared the EM — re-attach the tenant.
        $managed = $this->entityManager->find(Tenant::class, $tenantId);
        if ($managed instanceof Tenant) {
            $this->tenantContext->set($managed);
        }

        $connection = $this->entityManager->getConnection();

        // Pre-check EVERY boundary before deleting ANYTHING (all-or-nothing).
        $blocked = [];
        $attributeIds = [];
        if ([] !== $createdAttributes) {
            // tenant-safe: explicit tenant_id predicate; RLS is the backstop.
            $rows = $connection->fetchAllAssociative(
                'SELECT a.id, a.code,
                        (SELECT COUNT(*) FROM object_values ov WHERE ov.attribute_id = a.id) AS value_count
                   FROM attributes a
                  WHERE a.tenant_id = :tenant AND a.code IN (:codes)',
                ['tenant' => $tenantId, 'codes' => $createdAttributes],
                ['codes' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
            foreach ($rows as $row) {
                if (!\is_string($row['id']) || !\is_string($row['code'])) {
                    continue;
                }
                $valueCount = \is_numeric($row['value_count']) ? (int) $row['value_count'] : 1;
                if ($valueCount > 0) {
                    $blocked[] = ['code' => $row['code'], 'reason' => \sprintf('Attribute "%s" already carries %d value(s) - clear the data first or keep the attribute.', $row['code'], $valueCount)];
                    continue;
                }
                $attributeIds[$row['code']] = $row['id'];
            }
        }

        $groupIds = [];
        if ([] !== $createdGroups) {
            $deletableAttributeIds = array_values($attributeIds);
            // tenant-safe: explicit tenant_id predicate.
            $rows = $connection->fetchAllAssociative(
                'SELECT g.id, g.code,
                        (SELECT COUNT(*) FROM attribute_group_attributes aga
                          WHERE aga.attribute_group_id = g.id'
                          .([] !== $deletableAttributeIds ? ' AND aga.attribute_id NOT IN (:ours)' : '').') AS foreign_attachments,
                        (SELECT COUNT(*) FROM object_type_attribute_groups otag WHERE otag.attribute_group_id = g.id) AS type_links
                   FROM attribute_groups g
                  WHERE g.tenant_id = :tenant AND g.code IN (:codes)',
                array_merge(
                    ['tenant' => $tenantId, 'codes' => $createdGroups],
                    [] !== $deletableAttributeIds ? ['ours' => $deletableAttributeIds] : [],
                ),
                array_merge(
                    ['codes' => \Doctrine\DBAL\ArrayParameterType::STRING],
                    [] !== $deletableAttributeIds ? ['ours' => \Doctrine\DBAL\ArrayParameterType::STRING] : [],
                ),
            );
            foreach ($rows as $row) {
                if (!\is_string($row['id']) || !\is_string($row['code'])) {
                    continue;
                }
                $foreignAttachments = \is_numeric($row['foreign_attachments']) ? (int) $row['foreign_attachments'] : 1;
                $typeLinks = \is_numeric($row['type_links']) ? (int) $row['type_links'] : 1;
                if ($foreignAttachments > 0 || $typeLinks > 0) {
                    $blocked[] = ['code' => $row['code'], 'reason' => \sprintf('Group "%s" still has attachments outside this batch - detach them first or keep the group.', $row['code'])];
                    continue;
                }
                $groupIds[$row['code']] = $row['id'];
            }
        }

        if ([] !== $blocked) {
            return new SchemaRollbackResult(performed: false, removedAttributes: [], removedGroups: [], blocked: $blocked);
        }

        $connection->beginTransaction();
        try {
            // Detach OUR dataless attributes from every junction the commit
            // created, then delete through the guarded CQRS commands.
            // tenant-safe: ids were selected under the tenant predicate above.
            foreach ($attributeIds as $attributeId) {
                $connection->executeStatement('DELETE FROM attribute_group_attributes WHERE attribute_id = :id', ['id' => $attributeId]);
                $connection->executeStatement('DELETE FROM object_type_attributes WHERE attribute_id = :id', ['id' => $attributeId]);
                $this->commandBus->dispatch(new DeleteAttributeCommand(Uuid::fromString($attributeId)));
            }
            foreach ($groupIds as $groupId) {
                $this->commandBus->dispatch(new DeleteAttributeGroupCommand(Uuid::fromString($groupId)));
            }

            $this->entityManager->flush();
            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $failure;
        }

        return new SchemaRollbackResult(
            performed: true,
            removedAttributes: array_keys($attributeIds),
            removedGroups: array_keys($groupIds),
            blocked: [],
        );
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
