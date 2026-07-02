<?php

declare(strict_types=1);

namespace App\Catalog\Application\PendingChanges;

use App\Catalog\Application\Filter\FilterDslResolver;
use App\Catalog\Application\Validation\AttributeValueValidator;
use App\Catalog\Contracts\Command\BulkEditValuesPort;
use App\Catalog\Contracts\Command\ValueEditProposal;
use App\Catalog\Contracts\PendingChanges\PendingChangeDraft;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Provenance;
use App\Identity\Contracts\Policy\UserScopedPermissionCheckerInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Generator;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * AGENT-P3-01 (#1961) — materializes a bulk value edit as pending
 * diffs, never touching the catalog:
 *
 *   1. selector -> object ids through the SAME DSL compiler the feed
 *      scope uses (FilterDslResolver::toCountSql over `objects`);
 *   2. per attribute: existence check + per-attribute RBAC BY USER ID
 *      (P1-02 seam; a code outside the user's edit scope is rejected,
 *      not silently dropped) + the SAME AttributeValueValidator manual
 *      edits use — an invalid value rejects the whole code with the
 *      first error message (reported in the plan);
 *   3. before/after envelopes from/into the canonical shape
 *      (docs/api/jsonb-schemas.md), before read from the GLOBAL
 *      attributes_indexed reading;
 *   4. drafts stream into PendingChangesPort.materialize (chunked
 *      flush+clear inside) with provenance=agent.
 *
 * The catalog write happens ONLY post-approval through the real
 * bulk-path (P3-02). MVP scope: global values (no locale/channel
 * overlay writes) - the same basis the plan's counts use.
 */
final readonly class BulkEditValuesMaterializer implements BulkEditValuesPort
{
    private const int ID_CHUNK = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
        private FilterDslResolver $filterResolver,
        private AttributeValueValidator $valueValidator,
        private UserScopedPermissionCheckerInterface $permissions,
        private PendingChangesPort $pendingChanges,
    ) {
    }

    public function materializeValueEdits(
        Uuid $batchId,
        Uuid $userId,
        string $objectTypeCode,
        array $filterDsl,
        array $changes,
        string $mode,
    ): ValueEditProposal {
        if (!\in_array($mode, ['overwrite', 'only_empty'], true)) {
            throw new InvalidArgumentException(\sprintf('Unknown mode "%s" (use overwrite or only_empty).', $mode));
        }
        if ([] === $changes) {
            throw new InvalidArgumentException('No changes given.');
        }

        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Cannot materialize value edits without a current tenant.');
        }

        $objectType = $this->entityManager->getRepository(ObjectType::class)
            ->findOneBy(['code' => $objectTypeCode, 'tenant' => $tenant]);
        if (!$objectType instanceof ObjectType) {
            throw new InvalidArgumentException(\sprintf('Unknown object type "%s".', $objectTypeCode));
        }

        [$validChanges, $rejected] = $this->screenChanges($tenant, $userId, $changes);

        $objectIds = $this->resolveObjectIds($tenant, $objectType, $filterDsl);

        $affectedObjects = 0;
        $materialized = 0;
        $skippedExisting = 0;

        if ([] !== $validChanges && [] !== $objectIds) {
            $drafts = $this->draftGenerator(
                $tenant,
                $objectIds,
                $validChanges,
                $mode,
                $affectedObjects,
                $materialized,
                $skippedExisting,
            );
            $this->pendingChanges->materialize($batchId, Provenance::Agent->value, $drafts);
        }

        return new ValueEditProposal(
            batchId: $batchId,
            affectedObjects: $affectedObjects,
            materializedChanges: $materialized,
            skippedExisting: $skippedExisting,
            rejected: $rejected,
        );
    }

    /**
     * Per-attribute screening: existence, per-attribute RBAC by user id,
     * value validation with the manual-edit validators.
     *
     * @param array<string, mixed> $changes
     *
     * @return array{0: array<string, array{value: mixed}>, 1: list<array{code: string, reason: string}>}
     */
    private function screenChanges(Tenant $tenant, Uuid $userId, array $changes): array
    {
        $valid = [];
        $rejected = [];

        foreach ($changes as $code => $rawValue) {
            if ('' === $code) {
                $rejected[] = ['code' => $code, 'reason' => 'Attribute code must be a non-empty string.'];
                continue;
            }

            $attribute = $this->entityManager->getRepository(Attribute::class)
                ->findOneBy(['code' => $code, 'tenant' => $tenant]);
            if (!$attribute instanceof Attribute) {
                $rejected[] = ['code' => $code, 'reason' => 'Unknown attribute.'];
                continue;
            }

            if (!$this->permissions->canEditAttribute($userId, $attribute->getId())) {
                $rejected[] = ['code' => $code, 'reason' => 'Attribute is outside your edit permissions.'];
                continue;
            }

            $envelope = ['value' => $rawValue];
            try {
                $errors = $this->valueValidator->validate($attribute, $envelope);
            } catch (Throwable $failure) {
                $rejected[] = ['code' => $code, 'reason' => 'Validation failed: '.$failure->getMessage()];
                continue;
            }
            if ([] !== $errors) {
                $rejected[] = ['code' => $code, 'reason' => $errors[0]->message];
                continue;
            }

            $valid[$code] = $envelope;
        }

        return [$valid, $rejected];
    }

    /**
     * @param array<string, mixed> $filterDsl
     *
     * @return list<string>
     */
    private function resolveObjectIds(Tenant $tenant, ObjectType $objectType, array $filterDsl): array
    {
        $where = '';
        if ([] !== $filterDsl) {
            $this->filterResolver->validate($filterDsl);
            $fragment = $this->filterResolver->toCountSql($filterDsl);
            if (null === $fragment) {
                throw new InvalidArgumentException('Filter DSL targets attributes that are not indexed yet.');
            }
            $where = ' AND ('.$fragment.')';
        }

        // tenant-safe: explicit tenant_id predicate (raw SQL bypasses the
        // Doctrine TenantFilter); RLS is the backstop. Same selection SQL
        // as the XMLF feed scope (ExportBuilderFeedValues).
        $rows = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT co.id FROM objects co WHERE co.tenant_id = :tenant AND co.object_type_id = :otid'.$where,
            [
                'tenant' => $tenant->getId()->toRfc4122(),
                'otid' => $objectType->getId()->toRfc4122(),
            ],
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
     * Streams drafts without hydrating entities: before-values come from
     * the GLOBAL attributes_indexed reading, fetched per id-chunk.
     *
     * @param list<string>                       $objectIds
     * @param array<string, array{value: mixed}> $validChanges
     *
     * @return Generator<PendingChangeDraft>
     */
    private function draftGenerator(
        Tenant $tenant,
        array $objectIds,
        array $validChanges,
        string $mode,
        int &$affectedObjects,
        int &$materialized,
        int &$skippedExisting,
    ): Generator {
        $connection = $this->entityManager->getConnection();

        foreach (array_chunk($objectIds, self::ID_CHUNK) as $chunk) {
            // tenant-safe: ids were selected under the tenant predicate above;
            // re-scope defensively anyway.
            $rows = $connection->fetchAllAssociative(
                'SELECT co.id, co.attributes_indexed FROM objects co WHERE co.tenant_id = :tenant AND co.id IN (:ids)',
                ['tenant' => $tenant->getId()->toRfc4122(), 'ids' => $chunk],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );

            foreach ($rows as $row) {
                $objectId = \is_string($row['id'] ?? null) ? $row['id'] : null;
                if (null === $objectId) {
                    continue;
                }
                $indexedRaw = $row['attributes_indexed'] ?? '{}';
                $indexed = \is_string($indexedRaw) ? json_decode($indexedRaw, true) : $indexedRaw;
                if (!\is_array($indexed)) {
                    $indexed = [];
                }

                $objectTouched = false;
                foreach ($validChanges as $code => $afterEnvelope) {
                    /** @var array<string, mixed>|null $before */
                    $before = \is_array($indexed[$code] ?? null) ? $indexed[$code] : null;
                    if (null !== $before && !\array_key_exists('value', $before)) {
                        $before = ['value' => $before];
                    }

                    $beforeValue = null !== $before ? ($before['value'] ?? null) : null;
                    if ('only_empty' === $mode && null !== $beforeValue && '' !== $beforeValue) {
                        ++$skippedExisting;
                        continue;
                    }

                    $objectTouched = true;
                    ++$materialized;

                    yield new PendingChangeDraft(
                        changeType: PendingChangeType::Value,
                        targetObjectId: Uuid::fromString($objectId),
                        attributeCode: $code,
                        before: $before,
                        after: $afterEnvelope,
                    );
                }
                if ($objectTouched) {
                    ++$affectedObjects;
                }
            }
        }
    }
}
