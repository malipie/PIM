<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Event;

use App\Shared\Application\TenantAwareMessage;
use App\Shared\Domain\DomainEvent;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * WFL-P3-03 (#2425) — an editor without unpublish rights asked the
 * approvers to take a published object back to draft (PRD §3.8
 * "Request unpublish" UX). Not a transition — the state is untouched;
 * the fan-out notifies workflow.transition.unpublish grantees and
 * WFL-P4-02 upgrades this to a task.
 */
final readonly class ObjectUnpublishRequested implements DomainEvent, TenantAwareMessage
{
    public function __construct(
        public Uuid $objectId,
        public Uuid $tenantId,
        public ?Uuid $requesterId = null,
        public ?string $comment = null,
        public DateTimeImmutable $occurredOn = new DateTimeImmutable(),
    ) {
    }

    public function occurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public function eventName(): string
    {
        return 'catalog.object.unpublish_requested';
    }

    public function aggregateId(): string
    {
        return $this->objectId->toRfc4122();
    }

    public function tenantId(): Uuid
    {
        return $this->tenantId;
    }
}
