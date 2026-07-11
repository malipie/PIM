<?php

declare(strict_types=1);

namespace App\Workflow\Domain\Entity;

use App\Shared\Application\TenantScoped;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * WFL-P5-01 (#2431) — one tenant-editable editorial state machine
 * (ADR-0029 pillar 7). Shape is validated on the write edge by
 * WorkflowDefinitionValidator; the runtime loader builds a Symfony
 * Workflow (with per-transition metadata: permission, comment_required,
 * completeness_gate) from these rows when WORKFLOW_CUSTOM_DEFINITIONS
 * is on. `objectTypeId` NULL = applies to every ObjectType.
 */
class WorkflowDefinition implements TenantScoped
{
    private Uuid $id;
    private ?Tenant $tenant = null;
    private string $name;
    private ?Uuid $objectTypeId;

    /** @var list<array<string, mixed>> */
    private array $places;

    /** @var list<array<string, mixed>> */
    private array $transitions;

    private bool $enabled = false;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;

    /**
     * @param list<array<string, mixed>> $places
     * @param list<array<string, mixed>> $transitions
     */
    public function __construct(string $name, array $places, array $transitions, ?Uuid $objectTypeId = null)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->places = $places;
        $this->transitions = $transitions;
        $this->objectTypeId = $objectTypeId;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
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
            throw new LogicException('WorkflowDefinition rows cannot move between tenants.');
        }

        $this->tenant = $tenant;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getObjectTypeId(): ?Uuid
    {
        return $this->objectTypeId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getPlaces(): array
    {
        return $this->places;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTransitions(): array
    {
        return $this->transitions;
    }

    /**
     * @param list<array<string, mixed>> $places
     * @param list<array<string, mixed>> $transitions
     */
    public function updateShape(string $name, array $places, array $transitions, ?Uuid $objectTypeId): void
    {
        $this->name = $name;
        $this->places = $places;
        $this->transitions = $transitions;
        $this->objectTypeId = $objectTypeId;
        $this->touch();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        $this->touch();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
