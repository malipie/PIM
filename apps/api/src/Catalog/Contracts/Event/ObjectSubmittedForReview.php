<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Event;

use App\Shared\Application\TenantAwareMessage;
use App\Shared\Domain\DomainEvent;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * WFL-P2-01 (#2420) — draft entered the review queue (transition
 * `submit_for_review` of the object_editorial machine, ADR-0029).
 * Carries the submitter's comment for the review queue metadata and
 * the notification fan-out (WFL-P2-02).
 */
final readonly class ObjectSubmittedForReview implements DomainEvent, TenantAwareMessage
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
        return 'catalog.object.submitted_for_review';
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
