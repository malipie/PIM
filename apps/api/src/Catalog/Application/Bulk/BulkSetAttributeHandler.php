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
 * VIEW-12 (#543) — handler for `set_attribute` bulk action.
 *
 * Synchronous in MVP (<100 SKU per click). Async via Symfony Messenger
 * follows in VIEW-12.1. The shared chunked-loop + session lifecycle lives
 * in {@see AbstractBulkHandler} (AUD-056); this class only carries the
 * per-object `set_attribute` body.
 *
 * Returns a result summary the controller serialises to the wizard's
 * Step 3 stat grid. `BulkSession` carries the rollback recipe via the
 * accompanying `bulk_logs` rows.
 */
final class BulkSetAttributeHandler extends AbstractBulkHandler
{
    private string $attrCode = '';
    private mixed $newValue = null;
    /** #2314 — set when the attribute is relation-type; see BulkRelationApplier. */
    private ?Uuid $relationAttributeId = null;
    /** @var list<string> */
    private array $relationTargetIds = [];

    public function __construct(
        CatalogObjectRepositoryInterface $catalogObjects,
        EntityManagerInterface $em,
        BulkContext $bulkContext,
        private readonly AttributeLockReader $lockReader,
        BulkReindexQueueInterface $reindexQueue,
        private readonly BulkRelationApplier $relationApplier,
        private readonly BulkValueCanonicalizer $canonicalizer,
    ) {
        parent::__construct($catalogObjects, $em, $bulkContext, $reindexQueue);
    }

    /**
     * Apply a `set_attribute` action to every target id, writing
     * BulkLog rows for the rollback path. Locked attributes (VIEW-18)
     * skip with a warning entry.
     *
     * @return array{success: int, skipped: int, error: int}
     */
    public function handle(BulkSession $session, string $attrCode, mixed $newValue): array
    {
        $this->attrCode = $attrCode;
        $this->newValue = $newValue;

        // #2314 — relation attributes write link rows, not attributesIndexed.
        $this->relationAttributeId = $this->relationApplier->relationAttributeId($attrCode);
        $this->relationTargetIds = null !== $this->relationAttributeId
            ? $this->relationApplier->parseTargetIds($newValue)
            : [];

        return $this->runBatch($session);
    }

    protected function processObject(CatalogObject $object, BulkSession $session, BulkCounters $counters): void
    {
        if (null !== $this->relationAttributeId) {
            $this->relationApplier->apply(
                BulkRelationApplier::MODE_REPLACE,
                $object,
                $this->relationAttributeId,
                $this->relationTargetIds,
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

        $oldValue = $object->getAttributesIndexed()[$this->attrCode] ?? null;

        // Set in attributesIndexed (denormalised JSONB). #2664 — canonicalise
        // the raw value into the same envelope the single-edit path produces
        // (price → {amount,currency}, select → {option_code}) so the typed
        // detail-form input renders it; the bare scalar left the field empty.
        // The canonical object_values write is the deferred VIEW-13 refactor.
        $canonicalValue = $this->canonicalizer->canonicalize($this->attrCode, $this->newValue);
        $indexed = $object->getAttributesIndexed();
        $indexed[$this->attrCode] = $canonicalValue;
        $object->updateAttributeIndex($indexed);
        $object->markTouchedByBulkSession($session->getId());

        $this->em->persist(new BulkLog(
            $session->getId(),
            $object->getId(),
            null,
            $oldValue,
            $canonicalValue,
            BulkLog::LEVEL_INFO,
            null,
        ));

        ++$counters->success;
    }
}
