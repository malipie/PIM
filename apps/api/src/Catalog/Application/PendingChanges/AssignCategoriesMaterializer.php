<?php

declare(strict_types=1);

namespace App\Catalog\Application\PendingChanges;

use App\Catalog\Application\Filter\FilterDslResolver;
use App\Catalog\Contracts\Command\AssignCategoriesPort;
use App\Catalog\Contracts\Command\CategoryAssignProposal;
use App\Catalog\Contracts\PendingChanges\PendingChangeDraft;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Provenance;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Generator;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P3-05 (#1965) — materializes a bulk category assignment as
 * pending diffs, never touching the junction:
 *
 *   1. category ids are screened: the object must exist AND be of
 *      kind=category (assigning a product to another product is a
 *      rejection, not a crash);
 *   2. selector -> object ids through the SAME DSL compiler as the
 *      value materializer (P3-01);
 *   3. one Category draft per object: before = the object's current
 *      category-id list (read per chunk from the junction), after =
 *      operation + category ids — the operator's diff shows exactly
 *      what changes where;
 *   4. drafts stream into PendingChangesPort with provenance=agent.
 *
 * The junction write happens ONLY post-approval, through the existing
 * bulk category handlers (P3-02 committer), so the undo-log and the
 * 24h rollback come for free.
 */
final readonly class AssignCategoriesMaterializer implements AssignCategoriesPort
{
    private const int ID_CHUNK = 500;
    private const array OPERATIONS = ['add', 'remove', 'move'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
        private FilterDslResolver $filterResolver,
        private PendingChangesPort $pendingChanges,
    ) {
    }

    public function materializeCategoryAssignments(
        Uuid $batchId,
        Uuid $userId,
        string $objectTypeCode,
        array $filterDsl,
        array $categoryIds,
        string $operation,
        ?array $selectedIds = null,
    ): CategoryAssignProposal {
        if (!\in_array($operation, self::OPERATIONS, true)) {
            throw new InvalidArgumentException(\sprintf('Unknown operation "%s" (use add, remove or move).', $operation));
        }
        if ([] === $categoryIds) {
            throw new InvalidArgumentException('No category ids given.');
        }

        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Cannot materialize category assignments without a current tenant.');
        }

        $objectType = $this->entityManager->getRepository(ObjectType::class)
            ->findOneBy(['code' => $objectTypeCode, 'tenant' => $tenant]);
        if (!$objectType instanceof ObjectType) {
            throw new InvalidArgumentException(\sprintf('Unknown object type "%s".', $objectTypeCode));
        }

        [$validCategoryIds, $rejected] = $this->screenCategories($categoryIds);

        $objectIds = [];
        if ([] !== $validCategoryIds) {
            $objectIds = $this->resolveObjectIds($tenant, $objectType, $filterDsl, $selectedIds);
        }

        $affectedObjects = 0;
        if ([] !== $objectIds) {
            $drafts = $this->draftGenerator($tenant, $objectIds, $validCategoryIds, $operation, $affectedObjects);
            $this->pendingChanges->materialize($batchId, Provenance::Agent->value, $drafts);
        }

        return new CategoryAssignProposal(
            batchId: $batchId,
            affectedObjects: $affectedObjects,
            rejected: $rejected,
        );
    }

    /**
     * @param list<string> $categoryIds
     *
     * @return array{0: list<string>, 1: list<array{id: string, reason: string}>}
     */
    private function screenCategories(array $categoryIds): array
    {
        $valid = [];
        $rejected = [];

        foreach (array_values(array_unique($categoryIds)) as $id) {
            if (!Uuid::isValid($id)) {
                $rejected[] = ['id' => $id, 'reason' => 'Not a valid UUID.'];
                continue;
            }

            $category = $this->entityManager->getRepository(CatalogObject::class)->find(Uuid::fromString($id));
            if (!$category instanceof CatalogObject) {
                $rejected[] = ['id' => $id, 'reason' => 'Unknown category.'];
                continue;
            }

            if (ObjectKind::Category !== $category->getObjectType()->getKind()) {
                $rejected[] = ['id' => $id, 'reason' => 'Object is not a category.'];
                continue;
            }

            $valid[] = $id;
        }

        return [$valid, $rejected];
    }

    /**
     * When $selectedIds is given (the operator's current SELECTION, #2153)
     * it is THE selector, validated against tenant + object type so a stray
     * id can never widen the scope. Otherwise the filter DSL is the selector
     * ([] = every object of the type).
     *
     * @param array<string, mixed> $filterDsl
     * @param list<mixed>|null     $selectedIds
     *
     * @return list<string>
     */
    private function resolveObjectIds(Tenant $tenant, ObjectType $objectType, array $filterDsl, ?array $selectedIds = null): array
    {
        $params = [
            'tenant' => $tenant->getId()->toRfc4122(),
            'otid' => $objectType->getId()->toRfc4122(),
        ];
        $types = [];
        $where = '';

        if (null !== $selectedIds) {
            $clean = [];
            foreach ($selectedIds as $id) {
                if (\is_string($id) && Uuid::isValid($id)) {
                    $clean[] = $id;
                }
            }
            if ([] === $clean) {
                return [];
            }
            $where = ' AND co.id IN (:ids)';
            $params['ids'] = $clean;
            $types['ids'] = ArrayParameterType::STRING;
        } elseif ([] !== $filterDsl) {
            $this->filterResolver->validate($filterDsl);
            $fragment = $this->filterResolver->toCountSql($filterDsl);
            if (null === $fragment) {
                throw new InvalidArgumentException('Filter DSL targets attributes that are not indexed yet.');
            }
            $where = ' AND ('.$fragment.')';
        }

        // tenant-safe: explicit tenant_id predicate (raw SQL bypasses the
        // Doctrine TenantFilter); RLS is the backstop.
        $rows = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT co.id FROM objects co WHERE co.tenant_id = :tenant AND co.object_type_id = :otid'.$where,
            $params,
            $types,
        );

        $ids = [];
        foreach ($rows as $id) {
            if (\is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param list<string> $objectIds
     * @param list<string> $categoryIds
     *
     * @return Generator<PendingChangeDraft>
     */
    private function draftGenerator(
        Tenant $tenant,
        array $objectIds,
        array $categoryIds,
        string $operation,
        int &$affectedObjects,
    ): Generator {
        $connection = $this->entityManager->getConnection();

        foreach (array_chunk($objectIds, self::ID_CHUNK) as $chunk) {
            // tenant-safe: ids were selected under the tenant predicate above.
            $rows = $connection->fetchAllAssociative(
                'SELECT oc.object_id, oc.category_id FROM object_categories oc WHERE oc.object_id IN (:ids)',
                ['ids' => $chunk],
                ['ids' => ArrayParameterType::STRING],
            );

            $existingByObject = [];
            foreach ($rows as $row) {
                if (\is_string($row['object_id']) && \is_string($row['category_id'])) {
                    $existingByObject[$row['object_id']][] = $row['category_id'];
                }
            }

            foreach ($chunk as $objectId) {
                ++$affectedObjects;

                yield new PendingChangeDraft(
                    changeType: PendingChangeType::Category,
                    targetObjectId: Uuid::fromString($objectId),
                    attributeCode: null,
                    before: ['category_ids' => $existingByObject[$objectId] ?? []],
                    after: ['operation' => $operation, 'category_ids' => $categoryIds],
                );
            }
        }
    }
}
