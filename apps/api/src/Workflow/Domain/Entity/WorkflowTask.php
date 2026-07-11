<?php

declare(strict_types=1);

namespace App\Workflow\Domain\Entity;

use App\Shared\Application\TenantScoped;
use App\Shared\Domain\Tenant;
use App\Workflow\Contracts\WorkflowTaskStatus;
use App\Workflow\Contracts\WorkflowTaskType;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * WFL-P4-01 (#2428) — one assignable work item of the editorial loop.
 * PRD §3.7: tasks are collaborative — visible to everyone with the
 * permission, assigned to a USER or a ROLE (one of the two). No
 * ownership semantics beyond assignment.
 */
class WorkflowTask implements TenantScoped
{
    private Uuid $id;
    private ?Tenant $tenant = null;
    private ?Uuid $objectId;
    private string $title;
    private WorkflowTaskType $type;
    private ?Uuid $assigneeUserId;
    private ?string $assigneeRoleCode;
    private ?DateTimeImmutable $dueDate;
    private WorkflowTaskStatus $status = WorkflowTaskStatus::Open;
    private ?Uuid $createdBy;
    private ?Uuid $resolvedBy = null;
    private ?DateTimeImmutable $resolvedAt = null;
    private ?string $comment;

    /** @var array<string, mixed>|null */
    private ?array $context;

    private DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed>|null $context
     */
    public function __construct(
        string $title,
        WorkflowTaskType $type,
        ?Uuid $objectId = null,
        ?Uuid $assigneeUserId = null,
        ?string $assigneeRoleCode = null,
        ?DateTimeImmutable $dueDate = null,
        ?Uuid $createdBy = null,
        ?string $comment = null,
        ?array $context = null,
    ) {
        if (null !== $assigneeUserId && null !== $assigneeRoleCode) {
            throw new LogicException('A task is assigned to a user OR a role, not both.');
        }

        $this->id = Uuid::v7();
        $this->title = $title;
        $this->type = $type;
        $this->objectId = $objectId;
        $this->assigneeUserId = $assigneeUserId;
        $this->assigneeRoleCode = $assigneeRoleCode;
        $this->dueDate = $dueDate;
        $this->createdBy = $createdBy;
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
            throw new LogicException('WorkflowTask rows cannot move between tenants.');
        }

        $this->tenant = $tenant;
    }

    public function getObjectId(): ?Uuid
    {
        return $this->objectId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getType(): WorkflowTaskType
    {
        return $this->type;
    }

    public function getAssigneeUserId(): ?Uuid
    {
        return $this->assigneeUserId;
    }

    public function getAssigneeRoleCode(): ?string
    {
        return $this->assigneeRoleCode;
    }

    public function reassign(?Uuid $userId, ?string $roleCode): void
    {
        if (null !== $userId && null !== $roleCode) {
            throw new LogicException('A task is assigned to a user OR a role, not both.');
        }
        $this->assertOpen();
        $this->assigneeUserId = $userId;
        $this->assigneeRoleCode = $roleCode;
    }

    public function getDueDate(): ?DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function getStatus(): WorkflowTaskStatus
    {
        return $this->status;
    }

    public function complete(?Uuid $resolvedBy): void
    {
        $this->assertOpen();
        $this->status = WorkflowTaskStatus::Done;
        $this->resolvedBy = $resolvedBy;
        $this->resolvedAt = new DateTimeImmutable();
    }

    public function cancel(?Uuid $resolvedBy): void
    {
        $this->assertOpen();
        $this->status = WorkflowTaskStatus::Cancelled;
        $this->resolvedBy = $resolvedBy;
        $this->resolvedAt = new DateTimeImmutable();
    }

    public function getCreatedBy(): ?Uuid
    {
        return $this->createdBy;
    }

    public function getResolvedBy(): ?Uuid
    {
        return $this->resolvedBy;
    }

    public function getResolvedAt(): ?DateTimeImmutable
    {
        return $this->resolvedAt;
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

    private function assertOpen(): void
    {
        if ($this->status->isTerminal()) {
            throw new LogicException(\sprintf('Task "%s" is already %s.', $this->id->toRfc4122(), $this->status->value));
        }
    }
}
