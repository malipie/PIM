<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Event;

use App\Shared\Application\TenantAwareMessage;
use App\Shared\Domain\DomainEvent;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * WFL-P2-02 (#2421) — review decision: approved (transition `approve`).
 * Distinct from ObjectPublished (state fact, also fired by the direct
 * `publish` shortcut): this one means "a reviewer accepted YOUR
 * submission" and drives the author notification + task auto-close.
 */
final readonly class ObjectApproved implements DomainEvent, TenantAwareMessage
{
    public function __construct(
        public Uuid $objectId,
        public Uuid $tenantId,
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
        return 'catalog.object.approved';
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
