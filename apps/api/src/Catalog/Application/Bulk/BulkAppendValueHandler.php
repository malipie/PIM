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
 * VIEW-13 (#545) — `append_value` bulk action (multiselect-safe).
 *
 * Appends a value to a list attribute. If the attribute slot is null or
 * scalar, it is promoted to `[old, new]` (dedup). If the value is
 * already present, the row is skipped (no-op, info log). Locked
 * attributes (VIEW-33 / PRD §8.3) skip with a warning entry. Shared
 * lifecycle: {@see AbstractBulkHandler}. Relation attributes append
 * link rows instead (#2314).
 */
final class BulkAppendValueHandler extends AbstractBulkHandler
{
    private string $attrCode = '';
    private mixed $value = null;
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
    ) {
        parent::__construct($catalogObjects, $em, $bulkContext, $reindexQueue);
    }

    /**
     * @return array{success: int, skipped: int, error: int}
     */
    public function handle(BulkSession $session, string $attrCode, mixed $value): array
    {
        $this->attrCode = $attrCode;
        $this->value = $value;
        $this->relationAttributeId = $this->relationApplier->relationAttributeId($attrCode);
        $this->relationTargetIds = null !== $this->relationAttributeId
            ? $this->relationApplier->parseTargetIds($value)
            : [];

        return $this->runBatch($session);
    }

    protected function processObject(CatalogObject $object, BulkSession $session, BulkCounters $counters): void
    {
        if (null !== $this->relationAttributeId) {
            $this->relationApplier->apply(
                BulkRelationApplier::MODE_APPEND,
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

        $indexed = $object->getAttributesIndexed();
        $oldValue = $indexed[$this->attrCode] ?? null;
        $list = match (true) {
            \is_array($oldValue) => $oldValue,
            null === $oldValue => [],
            default => [$oldValue],
        };

        if (\in_array($this->value, $list, true)) {
            ++$counters->skipped;
            $this->em->persist(new BulkLog(
                $session->getId(),
                $object->getId(),
                null,
                $oldValue,
                $oldValue,
                BulkLog::LEVEL_WARNING,
                'Value already present',
            ));

            return;
        }

        $list[] = $this->value;
        $indexed[$this->attrCode] = $list;
        $object->updateAttributeIndex($indexed);
        $object->markTouchedByBulkSession($session->getId());
        $this->em->persist(new BulkLog(
            $session->getId(),
            $object->getId(),
            null,
            $oldValue,
            $list,
            BulkLog::LEVEL_INFO,
            null,
        ));
        ++$counters->success;
    }
}
