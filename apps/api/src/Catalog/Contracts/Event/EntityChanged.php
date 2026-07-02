<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Event;

use App\Shared\Application\TenantAwareMessage;
use App\Shared\Domain\DomainEvent;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P0-05 (#1948) — generic lifecycle "something changed" hook.
 *
 * Emitted by core (EntityChangedEmitter) on persist/update of catalog
 * domain entities, independently of whether anyone listens. This is the
 * substrate the proactive data steward (epic 0.7 M8, P8-01) and future
 * audit consumers subscribe to — phase 2 attaches listeners without any
 * core migration. No consumer ships in MVP.
 *
 * Coarser than the specific domain events (ObjectAttributesChanged,
 * ObjectCategoriesChanged): those describe WHAT changed for targeted
 * consumers; this one only says THAT an entity changed.
 */
final readonly class EntityChanged implements DomainEvent, TenantAwareMessage
{
    public const string KIND_CREATED = 'created';
    public const string KIND_UPDATED = 'updated';

    /**
     * @param string $entityType short stable identifier (e.g. 'catalog_object', 'object_value')
     * @param string $changeKind self::KIND_CREATED | self::KIND_UPDATED
     */
    public function __construct(
        public string $entityType,
        public Uuid $entityId,
        public Uuid $tenantId,
        public string $changeKind,
        public DateTimeImmutable $occurredOn = new DateTimeImmutable(),
    ) {
    }

    public function occurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public function eventName(): string
    {
        return 'catalog.entity.changed';
    }

    public function aggregateId(): string
    {
        return $this->entityId->toRfc4122();
    }

    public function tenantId(): Uuid
    {
        return $this->tenantId;
    }
}
