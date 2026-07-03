<?php

declare(strict_types=1);

namespace App\Catalog\Application\PendingChanges;

use App\Catalog\Contracts\PendingChanges\PendingChangeDraft;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeStatus;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use App\Catalog\Contracts\PendingChanges\PendingChangeView;
use App\Catalog\Domain\Entity\PendingChange;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P0-03 (#1946) — Doctrine implementation of the pending-changes
 * approval gate (ADR-0024 c).
 *
 * Materialization is memory-safe under FrankenPHP worker mode: rows are
 * flushed and the identity map cleared every CHUNK drafts, so a 50k-diff
 * plan never accumulates managed entities. Status transitions run as
 * single DQL UPDATE statements guarded on `pending` (idempotent).
 */
final class PendingChangesService implements PendingChangesPort
{
    private const int CHUNK = 200;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function materialize(Uuid $batchId, string $provenance, iterable $drafts): int
    {
        // Stamp the tenant explicitly via a per-EM reference instead of
        // relying on TenantAssignmentListener: the per-chunk clear() below
        // detaches the Tenant instance held by TenantContext, and flushing
        // a new row pointing at a detached Tenant fails (lessons: XMLF
        // "em->clear() detachuje -> rebind/reload").
        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Cannot materialize pending changes without a current tenant.');
        }
        $tenantId = $tenant->getId();

        $count = 0;
        foreach ($drafts as $draft) {
            $this->assertValueEnvelope($draft);

            $change = new PendingChange(
                batchId: $batchId,
                provenance: $provenance,
                changeType: $draft->changeType,
                targetObjectId: $draft->targetObjectId,
                attributeCode: $draft->attributeCode,
                scopeLocale: $draft->scopeLocale,
                scopeChannel: $draft->scopeChannel,
                before: $draft->before,
                after: $draft->after,
                meta: $draft->meta,
            );
            $reference = $this->entityManager->getReference(Tenant::class, $tenantId);
            \assert($reference instanceof Tenant);
            $change->assignTenant($reference);
            $this->entityManager->persist($change);

            ++$count;
            if (0 === $count % self::CHUNK) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        return $count;
    }

    public function listBatch(Uuid $batchId, int $limit = 100, int $offset = 0): array
    {
        /** @var list<PendingChange> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('pc')
            ->from(PendingChange::class, 'pc')
            ->where('pc.batchId = :batch')
            ->setParameter('batch', $batchId, 'uuid')
            ->orderBy('pc.id', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return array_map($this->toView(...), $rows);
    }

    public function iterateBatch(Uuid $batchId): iterable
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('pc')
            ->from(PendingChange::class, 'pc')
            ->where('pc.batchId = :batch')
            ->setParameter('batch', $batchId, 'uuid')
            ->orderBy('pc.id', 'ASC')
            ->getQuery();

        /** @var iterable<PendingChange> $changes */
        $changes = $query->toIterable();

        $emitted = 0;
        foreach ($changes as $change) {
            yield $this->toView($change);

            ++$emitted;
            if (0 === $emitted % self::CHUNK) {
                $this->entityManager->clear();
            }
        }
        $this->entityManager->clear();
    }

    public function countBatch(Uuid $batchId): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(pc.id)')
            ->from(PendingChange::class, 'pc')
            ->where('pc.batchId = :batch')
            ->setParameter('batch', $batchId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    public function accept(Uuid $batchId): int
    {
        return $this->transition($batchId, PendingChangeStatus::Accepted);
    }

    public function reject(Uuid $batchId): int
    {
        return $this->transition($batchId, PendingChangeStatus::Rejected);
    }

    public function expire(Uuid $batchId): int
    {
        return $this->transition($batchId, PendingChangeStatus::Expired);
    }

    public function annotate(Uuid $changeId, array $meta): void
    {
        $change = $this->entityManager->find(PendingChange::class, $changeId);
        if (!$change instanceof PendingChange) {
            return;
        }
        $change->mergeMeta($meta);
        $this->entityManager->flush();
    }

    private function transition(Uuid $batchId, PendingChangeStatus $target): int
    {
        // Bulk DQL UPDATE bypasses the Doctrine TenantFilter (SQLFilter only
        // constrains entity loading), so the tenant guard is explicit here —
        // RLS remains the DB-level backstop.
        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Cannot transition pending changes without a current tenant.');
        }

        $affected = $this->entityManager->createQueryBuilder()
            ->update(PendingChange::class, 'pc')
            ->set('pc.status', ':target')
            ->set('pc.decidedAt', ':now')
            ->where('pc.batchId = :batch')
            ->andWhere('pc.status = :pending')
            ->andWhere('pc.tenant = :tenant')
            ->setParameter('target', $target->value)
            ->setParameter('now', new DateTimeImmutable())
            ->setParameter('batch', $batchId, 'uuid')
            ->setParameter('pending', PendingChangeStatus::Pending->value)
            ->setParameter('tenant', $tenant->getId(), 'uuid')
            ->getQuery()
            ->execute();

        // Bulk DQL UPDATE bypasses the identity map — drop stale managed
        // rows so subsequent reads in the same request see fresh statuses.
        $this->entityManager->clear();

        return $affected;
    }

    private function assertValueEnvelope(PendingChangeDraft $draft): void
    {
        if (PendingChangeType::Value !== $draft->changeType) {
            return;
        }

        foreach (['before' => $draft->before, 'after' => $draft->after] as $side => $state) {
            if (null !== $state && !\array_key_exists('value', $state)) {
                throw new InvalidArgumentException(\sprintf(
                    'Value-change draft %s state must be a value envelope with a "value" key (docs/api/jsonb-schemas.md); got keys: %s',
                    $side,
                    implode(', ', array_keys($state)),
                ));
            }
        }
    }

    private function toView(PendingChange $change): PendingChangeView
    {
        return new PendingChangeView(
            id: $change->getId(),
            batchId: $change->getBatchId(),
            provenance: $change->getProvenance(),
            changeType: $change->getChangeType(),
            status: $change->getStatus(),
            targetObjectId: $change->getTargetObjectId(),
            attributeCode: $change->getAttributeCode(),
            scopeLocale: $change->getScopeLocale(),
            scopeChannel: $change->getScopeChannel(),
            before: $change->getBefore(),
            after: $change->getAfter(),
            meta: $change->getMeta(),
            createdAt: $change->getCreatedAt(),
            decidedAt: $change->getDecidedAt(),
        );
    }
}
