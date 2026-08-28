<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Domain\AggregateRoot;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Proxy;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Pulls buffered domain events from every {@see AggregateRoot} that
 * Doctrine just flushed and dispatches them through the default
 * Messenger bus.
 *
 * Why postFlush rather than onFlush: events emitted from a domain
 * method may need the persisted state (auto-generated ids, the row
 * actually being there for downstream queries) — Symfony's onFlush
 * fires before Doctrine commits, postFlush after. The trade-off is
 * we cannot abort the flush from a subscriber; subscribers that need
 * to fail loudly should throw and the unit-of-work catches it the
 * same way it would for any post-commit listener.
 *
 * UnitOfWork iteration:
 *   - identityMap holds every managed entity (insertions are already
 *     in there at postFlush). We walk it once and skip non-aggregates.
 *   - pullEvents() empties the buffer so a second flush in the same
 *     request does not replay the same events.
 */
#[AsDoctrineListener(event: Events::postFlush)]
final readonly class DomainEventDispatcher
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    public function postFlush(PostFlushEventArgs $event): void
    {
        $unitOfWork = $event->getObjectManager()->getUnitOfWork();

        foreach ($unitOfWork->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                if (!$entity instanceof AggregateRoot) {
                    continue;
                }
                // A lazy aggregate proxy cannot have recorded an in-memory
                // event: none of its domain methods has run. Calling
                // pullEvents() would initialise it only to discover an empty
                // buffer, producing an N+1 whenever another entity's relation
                // seeded the identity map (notably ObjectValue rollback).
                if ($entity instanceof Proxy && !$entity->__isInitialized()) {
                    continue;
                }

                foreach ($entity->pullEvents() as $domainEvent) {
                    $this->messageBus->dispatch($domainEvent);
                }
            }
        }
    }
}
