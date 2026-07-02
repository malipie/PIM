<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Listener;

use App\Catalog\Contracts\Event\EntityChanged;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Shared\Domain\Tenant;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * AGENT-P0-05 (#1948) — emits the generic {@see EntityChanged} core hook
 * on persist/update of catalog domain entities (CatalogObject and
 * ObjectValue for now — the entities the proactive data steward cares
 * about; widening is additive).
 *
 * Dispatched through the in-process PSR event dispatcher, NOT Messenger:
 * with zero listeners (the MVP state) a dispatch is a no-op array lookup,
 * so a 50k-row import pays nothing, and Messenger's no-handler failure
 * mode is avoided entirely. A phase-2 listener (P8-01) that needs async
 * fan-out re-dispatches to its own transport.
 *
 * Worker-safe by construction: readonly, stateless, no buffering — every
 * event leaves this class synchronously (FrankenPHP memory rule §3.10).
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final readonly class EntityChangedEmitter
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->emit($args->getObject(), EntityChanged::KIND_CREATED);
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->emit($args->getObject(), EntityChanged::KIND_UPDATED);
    }

    private function emit(object $entity, string $changeKind): void
    {
        $entityType = match (true) {
            $entity instanceof CatalogObject => 'catalog_object',
            $entity instanceof ObjectValue => 'object_value',
            default => null,
        };
        if (null === $entityType) {
            return;
        }

        /** @var CatalogObject|ObjectValue $entity */
        $tenant = $entity->getTenant();
        if (!$tenant instanceof Tenant) {
            return;
        }

        $this->eventDispatcher->dispatch(new EntityChanged(
            entityType: $entityType,
            entityId: $entity->getId(),
            tenantId: $tenant->getId(),
            changeKind: $changeKind,
        ));
    }
}
