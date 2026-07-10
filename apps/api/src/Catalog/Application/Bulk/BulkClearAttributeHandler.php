<?php

declare(strict_types=1);

namespace App\Catalog\Application\Bulk;

use App\Catalog\Application\BulkContext;
use App\Catalog\Application\Lock\AttributeLockReader;
use App\Catalog\Application\Reindex\BulkReindexQueueInterface;
use App\Catalog\Domain\Entity\BulkLog;
use App\Catalog\Domain\Entity\BulkSession;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * VIEW-13 (#545) — `clear_attribute` bulk action.
 *
 * Removes the attribute slot from `attributes_indexed` (unset). Used
 * for resetting a field across N products. BulkLog records `old_value`
 * for the 24h rollback path. Locked attributes (VIEW-33 / PRD §8.3)
 * skip with a warning entry. Shared lifecycle: {@see AbstractBulkHandler}.
 * Relation attributes clear their link rows instead (#2314).
 */
final class BulkClearAttributeHandler extends AbstractBulkHandler
{
    private string $attrCode = '';
    private ?Uuid $relationAttributeId = null;

    public function __construct(
        CatalogObjectRepositoryInterface $catalogObjects,
        EntityManagerInterface $em,
        BulkContext $bulkContext,
        private readonly AttributeLockReader $lockReader,
        BulkReindexQueueInterface $reindexQueue,
        private readonly BulkRelationApplier $relationApplier,
    ) {
        parent::__construct($catalogObjects, $em, $bulkContext, $reindexQueue);
    }

    /**
     * @return array{success: int, skipped: int, error: int}
     */
    public function handle(BulkSession $session, string $attrCode): array
    {
        $this->attrCode = $attrCode;
        $this->relationAttributeId = $this->relationApplier->relationAttributeId($attrCode);

        return $this->runBatch($session);
    }

    protected function processObject(CatalogObject $object, BulkSession $session, BulkCounters $counters): void
    {
        if (null !== $this->relationAttributeId) {
            $this->relationApplier->apply(
                BulkRelationApplier::MODE_CLEAR,
                $object,
                $this->relationAttributeId,
                [],
                $session,
                $counters,
            );

            return;
        }

        if ($this->lockReader->isLocked($object, $this->attrCode)) {
            ++$counters->skipped;
            $this->em->persist(new BulkLog(
                $session->getId(),
                $object->getId(),
                null,
                $object->getAttributesIndexed()[$this->attrCode] ?? null,
                $object->getAttributesIndexed()[$this->attrCode] ?? null,
                BulkLog::LEVEL_WARNING,
                'Attribute locked',
            ));

            return;
        }

        $indexed = $object->getAttributesIndexed();
        $oldValue = $indexed[$this->attrCode] ?? null;
        unset($indexed[$this->attrCode]);
        $object->updateAttributeIndex($indexed);
        $object->markTouchedByBulkSession($session->getId());

        $this->em->persist(new BulkLog(
            $session->getId(),
            $object->getId(),
            null,
            $oldValue,
            null,
            BulkLog::LEVEL_INFO,
            null,
        ));
        ++$counters->success;
    }
}
