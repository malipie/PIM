<?php

declare(strict_types=1);

namespace App\Catalog\Application\PendingChanges;

use App\Catalog\Application\Bulk\BulkRollbackHandler;
use App\Catalog\Application\BulkContext;
use App\Catalog\Application\Message\ObjectValuesChangedMessage;
use App\Catalog\Application\Reindex\BulkReindexQueueInterface;
use App\Catalog\Contracts\Command\BulkRollbackPort;
use App\Catalog\Domain\Entity\BulkLog;
use App\Catalog\Domain\Entity\BulkSession;
use App\Catalog\Domain\Entity\ObjectValue;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P3-04 (#1964) — value-canonical rollback for agent batches.
 *
 * The existing BulkRollbackHandler replays old values onto the
 * attributes_indexed PROJECTION — right for the manual bulk actions
 * that only write that slice, wrong for agent commits (P3-02) whose
 * writes are canonical object_values rows: reverting the projection
 * alone would last exactly until the next rebuild resurrected the
 * agent's value. This handler replays the undo capture on
 * object_values itself and lets the projection + Meilisearch rebuild
 * from canon (one ObjectValuesChangedMessage per chunk).
 *
 * Guards:
 *   - BulkSession window (24h) + single use, same rules as manual ops;
 *   - superseded guard per row: the log's new_value must still be the
 *     row's current value — anything else means a later edit (manual
 *     or import) owns the value now, and rollback skips it.
 *
 * MVP scope: global values (the only thing P3-02 commits). The undo
 * capture stores envelopes, not the pre-agent provenance — a restored
 * overwrite therefore keeps provenance=agent (the value content is
 * back, the audit trail shows the agent touched it). A value the agent
 * created (before=null) is removed outright, so UC2 fill-empty rolls
 * back to a clean slate.
 */
final readonly class ObjectValueRollbackHandler implements BulkRollbackPort
{
    private const int CHUNK = 200;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private BulkContext $bulkContext,
        private BulkReindexQueueInterface $reindexQueue,
        private MessageBusInterface $messageBus,
        private BulkRollbackHandler $projectionRollback,
    ) {
    }

    public function rollbackSession(Uuid $bulkSessionId): int
    {
        $session = $this->entityManager->find(BulkSession::class, $bulkSessionId);
        if (!$session instanceof BulkSession) {
            throw new LogicException(\sprintf('Bulk session "%s" not found.', $bulkSessionId->toRfc4122()));
        }
        if (!$session->isRollbackAvailable()) {
            throw new BadRequestHttpException('Rollback window expired or already used.');
        }

        // AGENT-P3-05 (#1965) — category (and other junction/projection)
        // sessions were committed by the existing bulk handlers and are
        // reverted by the existing rollback path; only the agent's
        // value-canonical multi_attribute_edit sessions need this handler.
        if ('multi_attribute_edit' !== $session->getActionType()) {
            return $this->projectionRollback->rollback($session);
        }

        set_time_limit(0);

        $restored = 0;
        $this->bulkContext->setBulk(true, $bulkSessionId);
        try {
            $logs = $this->entityManager->createQueryBuilder()
                ->select('bl')
                ->from(BulkLog::class, 'bl')
                ->where('bl.bulkSessionId = :session')
                ->andWhere('bl.level = :info')
                ->setParameter('session', $bulkSessionId, 'uuid')
                ->setParameter('info', BulkLog::LEVEL_INFO)
                ->orderBy('bl.id', 'DESC')
                ->getQuery()
                ->toIterable();

            $chunk = 0;
            $touchedIds = [];
            /** @var BulkLog $log */
            foreach ($logs as $log) {
                if ($this->revertValue($log)) {
                    ++$restored;
                    $touchedIds[] = $log->getObjectId()->toRfc4122();
                }

                ++$chunk;
                if ($chunk >= self::CHUNK) {
                    $this->flushChunk($touchedIds);
                    $chunk = 0;
                    $touchedIds = [];
                }
            }
            if ($chunk > 0 || [] !== $touchedIds) {
                $this->flushChunk($touchedIds);
            }

            $fresh = $this->entityManager->find(BulkSession::class, $bulkSessionId);
            if ($fresh instanceof BulkSession) {
                $fresh->markRolledBack();
                $this->entityManager->flush();
            }

            $this->reindexQueue->queueAll($session->getTargetObjectIds());

            return $restored;
        } finally {
            $this->bulkContext->setBulk(false);
        }
    }

    /**
     * Replay one undo row on the canonical object_values. Returns false
     * when the row is skipped (superseded by a later edit or the
     * attribute id is missing).
     */
    private function revertValue(BulkLog $log): bool
    {
        $attributeId = $log->getAttributeId();
        if (!$attributeId instanceof Uuid) {
            return false;
        }

        $current = $this->entityManager->getRepository(ObjectValue::class)->findOneBy([
            'object' => $log->getObjectId(),
            'attribute' => $attributeId,
            'locale' => null,
            'channelId' => null,
        ]);

        $newValue = $log->getNewValue();
        $oldValue = $log->getOldValue();

        if (!$current instanceof ObjectValue) {
            // The agent's value is already gone (deleted later) — a later
            // edit owns this slot; restoring anything would clobber it.
            return false;
        }

        // Superseded guard: only roll back what the agent actually left
        // behind. Key order is normalised so JSON round-trip drift does
        // not defeat the comparison.
        if (self::normalise($current->getValue()) !== self::normalise($newValue)) {
            return false;
        }

        if (null === $oldValue) {
            // The value did not exist before the agent — remove it.
            $this->entityManager->remove($current);

            return true;
        }

        if (!\is_array($oldValue)) {
            return false;
        }

        /** @var array<string, mixed> $old */
        $old = $oldValue;
        $current->updateValue($old);

        return true;
    }

    private static function normalise(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        ksort($value);

        return array_map(self::normalise(...), $value);
    }

    /**
     * @param list<string> $touchedIds
     */
    private function flushChunk(array $touchedIds): void
    {
        $this->entityManager->flush();
        $this->entityManager->clear();

        if ([] !== $touchedIds) {
            // Projection + Meilisearch rebuild from the restored canon.
            $this->messageBus->dispatch(new ObjectValuesChangedMessage(array_values(array_unique($touchedIds))));
        }
    }
}
