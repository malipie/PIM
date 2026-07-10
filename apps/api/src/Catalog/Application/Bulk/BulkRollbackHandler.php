<?php

declare(strict_types=1);

namespace App\Catalog\Application\Bulk;

use App\Catalog\Application\BulkContext;
use App\Catalog\Application\Reindex\BulkReindexQueueInterface;
use App\Catalog\Domain\Entity\BulkLog;
use App\Catalog\Domain\Entity\BulkSession;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectCategoryRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * VIEW-17 / VIEW-34 — 24h soft rollback executor.
 *
 * Iterates `bulk_logs` (Doctrine `iterate()` for memory safety per
 * CLAUDE.md FrankenPHP rule) in reverse insertion order and replays
 * `old_value` per row. Marks the session as rolled back so the toast
 * disappears + the rollback button greys out.
 *
 * VIEW-34 (#574) extends VIEW-17 with per-action-type dispatch:
 *  - `set_attribute` / `clear_attribute` / `append_value` / `remove_value`
 *    / `increment_numeric` / `multi_attribute_edit` — replay `old_value`
 *    on the `attributes_indexed` slot keyed by `payload.attr` (or
 *    `BulkLog.message` for multi).
 *  - `add_category` / `remove_category` / `move_category` — replay
 *    full category-id list on `object_categories` junction via
 *    `replaceForProduct`.
 *  - `publish_channels` / `unpublish_channels` — replay
 *    `attributes_indexed.published` map.
 *  - `delete` / `duplicate` — currently NOT auto-reversed (delete
 *    needs recreate, duplicate needs delete-copy); recipes live in
 *    `BulkLog` for a future extension.
 *
 * Only logs with `level=info` are reversed — `error` rows were never
 * applied, and `warning` rows are skip-with-report entries that left
 * the row untouched.
 *
 * Hard expiry: rollback past `rollback_available_until` raises 400.
 */
final class BulkRollbackHandler
{
    public function __construct(
        private readonly CatalogObjectRepositoryInterface $catalogObjects,
        private readonly ObjectCategoryRepositoryInterface $objectCategories,
        private readonly EntityManagerInterface $em,
        private readonly BulkContext $bulkContext,
        private readonly BulkReindexQueueInterface $reindexQueue,
        private readonly BulkRelationApplier $relationApplier,
    ) {
    }

    public function rollback(BulkSession $session): int
    {
        // Long bulk rollbacks (reversing 2k+ products) routinely exceed
        // PHP's 30s HTTP timeout — without disabling it the handler is
        // killed mid-loop, the session stays incomplete, and the
        // operator sees 200 + zero rows touched.
        set_time_limit(0);

        if (!$session->isRollbackAvailable()) {
            throw new BadRequestHttpException('Rollback window expired or already used.');
        }

        $this->bulkContext->setBulk(true, $session->getId());
        $restored = 0;
        try {
            $logs = $this->em->getRepository(BulkLog::class)
                ->createQueryBuilder('l')
                ->where('l.bulkSessionId = :session')
                ->andWhere('l.level = :level')
                ->setParameter('session', $session->getId())
                ->setParameter('level', BulkLog::LEVEL_INFO)
                ->orderBy('l.createdAt', 'DESC')
                ->getQuery()
                ->toIterable();

            $action = $session->getActionType();
            $payload = $session->getActionPayload();
            $chunk = 0;

            // #2314 — relation attributes were mutated through the link
            // table, so their rollback replays the old target-id list via
            // the relation write path instead of attributesIndexed.
            $attrCode = \is_string($payload['attr'] ?? null) ? $payload['attr'] : null;
            $relationAttributeId = null !== $attrCode && \in_array($action, [
                'set_attribute', 'clear_attribute', 'append_value', 'remove_value',
            ], true)
                ? $this->relationApplier->relationAttributeId($attrCode)
                : null;

            foreach ($logs as $log) {
                $object = $this->catalogObjects->findById($log->getObjectId());
                if (!$object instanceof CatalogObject) {
                    continue;
                }

                $reverted = match (true) {
                    null !== $relationAttributeId => $this->relationApplier->revertTo(
                        $object,
                        $relationAttributeId,
                        $log->getOldValue(),
                    ),
                    \in_array($action, [
                        'set_attribute',
                        'clear_attribute',
                        'append_value',
                        'remove_value',
                        'increment_numeric',
                    ], true) => $this->revertAttributeSlot(
                        $object,
                        $log,
                        $attrCode,
                    ),
                    'multi_attribute_edit' === $action => $this->revertAttributeSlot(
                        $object,
                        $log,
                        $log->getMessage(),
                    ),
                    \in_array($action, ['add_category', 'remove_category', 'move_category'], true) => $this->revertCategoryAssignment($object, $log),
                    \in_array($action, ['publish_channels', 'unpublish_channels'], true) => $this->revertPublishedMap($object, $log),
                    default => false,
                };

                if ($reverted) {
                    ++$restored;
                }

                ++$chunk;
                if ($chunk >= 200) {
                    $this->em->flush();
                    $this->em->clear();
                    $chunk = 0;
                }
            }

            if ($chunk > 0) {
                $this->em->flush();
            }

            $sessionFresh = $this->em->find(BulkSession::class, $session->getId());
            if ($sessionFresh instanceof BulkSession) {
                $sessionFresh->markRolledBack();
                $this->em->flush();
            }

            $this->reindexQueue->queueAll($session->getTargetObjectIds());

            return $restored;
        } finally {
            $this->bulkContext->setBulk(false);
        }
    }

    private function revertAttributeSlot(
        CatalogObject $object,
        BulkLog $log,
        ?string $attrCode,
    ): bool {
        if (null === $attrCode || '' === $attrCode) {
            return false;
        }
        $indexed = $object->getAttributesIndexed();
        if (null === $log->getOldValue()) {
            unset($indexed[$attrCode]);
        } else {
            $indexed[$attrCode] = $log->getOldValue();
        }
        $object->updateAttributeIndex($indexed);

        return true;
    }

    private function revertCategoryAssignment(CatalogObject $object, BulkLog $log): bool
    {
        $oldValue = $log->getOldValue();
        if (!\is_array($oldValue)) {
            return false;
        }
        $categoryIds = [];
        foreach ($oldValue as $id) {
            if (\is_string($id) && '' !== $id) {
                $categoryIds[] = Uuid::fromString($id);
            }
        }
        $primaryId = $categoryIds[0] ?? null;
        $this->objectCategories->replaceForProduct($object, $categoryIds, $primaryId);

        return true;
    }

    private function revertPublishedMap(CatalogObject $object, BulkLog $log): bool
    {
        $oldValue = $log->getOldValue();
        if (!\is_array($oldValue)) {
            return false;
        }
        $indexed = $object->getAttributesIndexed();
        if ([] === $oldValue) {
            unset($indexed['published']);
        } else {
            $indexed['published'] = $oldValue;
        }
        $object->updateAttributeIndex($indexed);

        return true;
    }
}
