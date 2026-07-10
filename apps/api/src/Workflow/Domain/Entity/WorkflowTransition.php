<?php

declare(strict_types=1);

namespace App\Workflow\Domain\Entity;

use App\Shared\Application\TenantScoped;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * WFL-P0-04 (#2413) — one applied transition of an editorial workflow
 * (ADR-0029 pillar 5, Pimcore "notes & events" pattern). Append-only:
 * rows are written by {@see \App\Workflow\Infrastructure\EventSubscriber\TransitionLoggingSubscriber}
 * on `workflow.*.completed` and never mutated.
 *
 * `objectId` is a bare UUID (ADR-0015) — the log survives object
 * deletion as audit history. `actorUserId` is NULL for system-applied
 * transitions (bulk workers, agent hook). UUIDv7 ids double as the
 * cursor for pagination: they are time-ordered, so `id < :before`
 * pages backwards without a composite (created_at, id) cursor.
 */
class WorkflowTransition implements TenantScoped
{
    private Uuid $id;
    private ?Tenant $tenant = null;
    private Uuid $objectId;
    private string $workflowName;
    private string $transition;
    private string $fromPlace;
    private string $toPlace;
    private ?Uuid $actorUserId;
    private ?string $comment;

    /** @var array<string, mixed>|null */
    private ?array $context;

    private DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed>|null $context
     */
    public function __construct(
        Uuid $objectId,
        string $workflowName,
        string $transition,
        string $fromPlace,
        string $toPlace,
        ?Uuid $actorUserId = null,
        ?string $comment = null,
        ?array $context = null,
    ) {
        $this->id = Uuid::v7();
        $this->objectId = $objectId;
        $this->workflowName = $workflowName;
        $this->transition = $transition;
        $this->fromPlace = $fromPlace;
        $this->toPlace = $toPlace;
        $this->actorUserId = $actorUserId;
        $this->comment = $comment;
        $this->context = $context;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function assignTenant(Tenant $tenant): void
    {
        if (null !== $this->tenant && $this->tenant !== $tenant) {
            throw new LogicException('WorkflowTransition rows cannot move between tenants.');
        }

        $this->tenant = $tenant;
    }

    public function getObjectId(): Uuid
    {
        return $this->objectId;
    }

    public function getWorkflowName(): string
    {
        return $this->workflowName;
    }

    public function getTransition(): string
    {
        return $this->transition;
    }

    public function getFromPlace(): string
    {
        return $this->fromPlace;
    }

    public function getToPlace(): string
    {
        return $this->toPlace;
    }

    public function getActorUserId(): ?Uuid
    {
        return $this->actorUserId;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getContext(): ?array
    {
        return $this->context;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
