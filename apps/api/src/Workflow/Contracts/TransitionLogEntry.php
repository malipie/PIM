<?php

declare(strict_types=1);

namespace App\Workflow\Contracts;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * WFL-P0-04 (#2413) — read view of one logged workflow transition,
 * exposed through {@see TransitionLogPort} so other bounded contexts
 * (Catalog presentation, future task automation) never import the
 * Workflow entity (`Workflow_Contracts` → Shared + Vendor only).
 */
final readonly class TransitionLogEntry
{
    /**
     * @param array<string, mixed>|null $context
     */
    public function __construct(
        public Uuid $id,
        public Uuid $objectId,
        public string $workflowName,
        public string $transition,
        public string $fromPlace,
        public string $toPlace,
        public ?Uuid $actorUserId,
        public ?string $comment,
        public ?array $context,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
