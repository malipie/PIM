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
        ?array $selectedIds = null,
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

        $objectIds = $this->resolveObjectIds($tenant, $objectType, $filterDsl, $selectedIds);

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

    public function materializeArithmeticEdits(
        Uuid $batchId,
        Uuid $userId,
        string $objectTypeCode,
        array $filterDsl,
        string $attrCode,
        string $operator,
        float $operand,
        ?array $selectedIds = null,
    ): ValueEditProposal {
        if (!\in_array($operator, ['+', '-', '*', '/', '%'], true)) {
            throw new InvalidArgumentException(\sprintf('Unsupported operator "%s" (use + - * / %%).', $operator));
        }

        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Cannot materialize arithmetic edits without a current tenant.');
        }

        $objectType = $this->entityManager->getRepository(ObjectType::class)
            ->findOneBy(['code' => $objectTypeCode, 'tenant' => $tenant]);
        if (!$objectType instanceof ObjectType) {
            throw new InvalidArgumentException(\sprintf('Unknown object type "%s".', $objectTypeCode));
        }

        // Existence + per-attribute RBAC on the single target attribute
        // (mirrors screenChanges, minus value validation — the result is
        // computed per object, not supplied).
        $rejected = [];
        $attribute = '' === $attrCode ? null : $this->entityManager->getRepository(Attribute::class)
            ->findOneBy(['code' => $attrCode, 'tenant' => $tenant]);
        if (!$attribute instanceof Attribute) {
            $rejected[] = ['code' => $attrCode, 'reason' => 'Unknown attribute.'];
        } elseif (!$this->permissions->canEditAttribute($userId, $attribute->getId())) {
            $rejected[] = ['code' => $attrCode, 'reason' => 'Attribute is outside your edit permissions.'];
            $attribute = null;
        }

        $affectedObjects = 0;
        $materialized = 0;
        // Non-numeric current values (and division-by-zero) are skipped,
        // never errored — the operator cannot apply, so the object is left
        // untouched and counted here.
        $skipped = 0;

        if ($attribute instanceof Attribute) {
            $objectIds = $this->resolveObjectIds($tenant, $objectType, $filterDsl, $selectedIds);
            if ([] !== $objectIds) {
                $drafts = $this->arithmeticDraftGenerator(
                    $tenant,
                    $objectIds,
                    $attrCode,
                    $operator,
                    $operand,
                    $affectedObjects,
                    $materialized,
                    $skipped,
                );
                $this->pendingChanges->materialize($batchId, Provenance::Agent->value, $drafts);
            }
        }

        return new ValueEditProposal(
            batchId: $batchId,
            affectedObjects: $affectedObjects,
            materializedChanges: $materialized,
            skippedExisting: $skipped,
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
     * Resolve the objects a bulk operation targets. When $selectedIds is
     * given (the operator's current SELECTION, #2153), the selector is that
     * list — validated against tenant + object type so a stray/foreign id
     * from the model or client can never widen the scope (RLS is the
     * backstop; this is the explicit predicate). Otherwise the selector is
     * the filter DSL ([] = every object of the type).
     *
     * @param array<string, mixed> $filterDsl
     * @param list<mixed>|null     $selectedIds explicit selection, or null to use the filter
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
            // Intersect the selection with tenant + type — never trust the
            // raw id list; a foreign/mistyped id simply yields no row.
            $where = ' AND co.id IN (:ids)';
            $params['ids'] = $clean;
            $types['ids'] = \Doctrine\DBAL\ArrayParameterType::STRING;
        } elseif ([] !== $filterDsl) {
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

    /**
     * Streams arithmetic drafts: reads the current scalar from the
     * attributes_indexed envelope per object, applies the operator, and
     * yields a before->after diff. Non-numeric current values and
     * division-by-zero are skipped (counted), never errored.
     *
     * @param list<string> $objectIds
     *
     * @return Generator<PendingChangeDraft>
     */
    private function arithmeticDraftGenerator(
        Tenant $tenant,
        array $objectIds,
        string $attrCode,
        string $operator,
        float $operand,
        int &$affectedObjects,
        int &$materialized,
        int &$skipped,
    ): Generator {
        $connection = $this->entityManager->getConnection();

        foreach (array_chunk($objectIds, self::ID_CHUNK) as $chunk) {
            // tenant-safe: ids were selected under the tenant predicate above.
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

                /** @var array<string, mixed>|null $before */
                $before = \is_array($indexed[$attrCode] ?? null) ? $indexed[$attrCode] : null;
                if (null !== $before && !\array_key_exists('value', $before)) {
                    $before = ['value' => $before];
                }
                $beforeValue = null !== $before ? ($before['value'] ?? null) : null;
                if (!is_numeric($beforeValue)) {
                    ++$skipped;
                    continue;
                }

                $result = $this->applyOperator((float) $beforeValue, $operator, $operand);
                if (null === $result) {
                    ++$skipped;
                    continue;
                }

                ++$affectedObjects;
                ++$materialized;

                yield new PendingChangeDraft(
                    changeType: PendingChangeType::Value,
                    targetObjectId: Uuid::fromString($objectId),
                    attributeCode: $attrCode,
                    before: $before,
                    after: ['value' => $result],
                );
            }
        }
    }

    private function applyOperator(float $base, string $operator, float $operand): ?float
    {
        return match ($operator) {
            '+' => $base + $operand,
            '-' => $base - $operand,
            '*' => $base * $operand,
            '/' => 0.0 === $operand ? null : $base / $operand,
            '%' => 0.0 === $operand ? null : fmod($base, $operand),
            default => null,
        };
    }
}
