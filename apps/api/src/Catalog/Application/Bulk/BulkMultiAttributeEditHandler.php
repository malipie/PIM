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
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * VIEW-13 (#545) — `multi_attribute_edit` bulk action.
 *
 * Applies a list of (attr, op, value) tuples to each target in a single
 * transaction per object. Each attribute change emits its own BulkLog
 * (so rollback can replay individually). Supported ops: `set`, `clear`.
 * Locked attributes (VIEW-33 / PRD §8.3) skip per-edit with a warning
 * entry; other edits in the same row still apply. Shared lifecycle:
 * {@see AbstractBulkHandler}.
 *
 * Cmd+K killer use case (PRD §3.5): „skopiuj manufacturer do brand i
 * ustaw enabled=true dla wszystkich z manufacturer IS NOT EMPTY".
 */
final class BulkMultiAttributeEditHandler extends AbstractBulkHandler
{
    /** @var list<array{attr: string, op: string, value?: mixed}> */
    private array $edits = [];

    public function __construct(
        CatalogObjectRepositoryInterface $catalogObjects,
        EntityManagerInterface $em,
        BulkContext $bulkContext,
        private readonly AttributeLockReader $lockReader,
        BulkReindexQueueInterface $reindexQueue,
        private readonly BulkValueCanonicalizer $canonicalizer,
    ) {
        parent::__construct($catalogObjects, $em, $bulkContext, $reindexQueue);
    }

    /**
     * @param list<array{attr: string, op: string, value?: mixed}> $edits
     *
     * @return array{success: int, skipped: int, error: int}
     */
    public function handle(BulkSession $session, array $edits): array
    {
        if ([] === $edits) {
            throw new BadRequestHttpException('edits must be a non-empty list.');
        }

        $this->edits = $edits;

        return $this->runBatch($session);
    }

    protected function processObject(CatalogObject $object, BulkSession $session, BulkCounters $counters): void
    {
        $indexed = $object->getAttributesIndexed();
        $rowChanged = false;
        foreach ($this->edits as $edit) {
            $code = $edit['attr'];
            $op = $edit['op'];
            $oldValue = $indexed[$code] ?? null;

            if ($this->lockReader->isLocked($object, $code)) {
                ++$counters->skipped;
                $this->em->persist(new BulkLog(
                    $session->getId(),
                    $object->getId(),
                    null,
                    $oldValue,
                    $oldValue,
                    BulkLog::LEVEL_WARNING,
                    \sprintf('Attribute locked: %s', $code),
                ));
                continue;
            }

            if ('set' === $op) {
                // #2664 — canonicalise into the typed envelope (see BulkSetAttributeHandler).
                $newValue = $this->canonicalizer->canonicalize($code, $edit['value'] ?? null);
                $indexed[$code] = $newValue;
            } elseif ('clear' === $op) {
                $newValue = null;
                unset($indexed[$code]);
            } else {
                $this->em->persist(new BulkLog(
                    $session->getId(),
                    $object->getId(),
                    null,
                    $oldValue,
                    $oldValue,
                    BulkLog::LEVEL_ERROR,
                    \sprintf('Unsupported edit op "%s" on attr "%s"', $op, $code),
                ));
                continue;
            }

            $this->em->persist(new BulkLog(
                $session->getId(),
                $object->getId(),
                null,
                $oldValue,
                $newValue,
                BulkLog::LEVEL_INFO,
                $code,
            ));
            $rowChanged = true;
        }

        if ($rowChanged) {
            $object->updateAttributeIndex($indexed);
            $object->markTouchedByBulkSession($session->getId());
            ++$counters->success;
        }
    }
}
