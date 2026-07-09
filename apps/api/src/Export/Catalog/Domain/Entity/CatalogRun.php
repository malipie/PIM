<?php

declare(strict_types=1);

namespace App\Export\Catalog\Domain\Entity;

use App\Export\Catalog\Domain\Enum\CatalogRunStatus;
use App\Export\Catalog\Domain\Enum\CatalogRunTrigger;
use App\Shared\Application\TenantScoped;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * Audit of a single PDF catalog generation (ADR-0027 §6.3, CPDF-P1-01) — the
 * catalog-world analogue of {@see \App\Export\Feed\Domain\Entity\FeedRun}. On
 * success its file becomes the cached artifact the public URL serves.
 * Tenant-scoped with Postgres RLS.
 */
class CatalogRun extends AggregateRoot implements TenantScoped
{
    private Uuid $id;

    private ?Tenant $tenant = null;

    /** Bare uuid → {@see CatalogProfile} (DB-level FK, ON DELETE CASCADE). */
    private Uuid $catalogProfileId;

    private string $trigger;

    private string $status;

    private ?int $pageCount = null;

    private ?int $byteSize = null;

    private ?int $itemCount = null;

    private ?int $durationMs = null;

    private ?string $errorMessage = null;

    private ?DateTimeImmutable $startedAt = null;

    private ?DateTimeImmutable $completedAt = null;

    public function __construct(
        Uuid $catalogProfileId,
        CatalogRunTrigger $trigger,
        CatalogRunStatus $status = CatalogRunStatus::Pending,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->catalogProfileId = $catalogProfileId;
        $this->trigger = $trigger->value;
        $this->status = $status->value;
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
        if (null !== $this->tenant) {
            throw new LogicException('Tenant is already assigned and cannot be reassigned.');
        }
        $this->tenant = $tenant;
    }

    public function getCatalogProfileId(): Uuid
    {
        return $this->catalogProfileId;
    }

    public function getTrigger(): CatalogRunTrigger
    {
        return CatalogRunTrigger::from($this->trigger);
    }

    public function getStatus(): CatalogRunStatus
    {
        return CatalogRunStatus::from($this->status);
    }

    public function markRunning(): void
    {
        $this->status = CatalogRunStatus::Running->value;
        $this->startedAt = new DateTimeImmutable();
    }

    public function markDone(int $pageCount, int $byteSize, int $itemCount, int $durationMs): void
    {
        $this->status = CatalogRunStatus::Done->value;
        $this->pageCount = $pageCount;
        $this->byteSize = $byteSize;
        $this->itemCount = $itemCount;
        $this->durationMs = $durationMs;
        $this->completedAt = new DateTimeImmutable();
    }

    public function markError(string $message): void
    {
        $this->status = CatalogRunStatus::Error->value;
        $this->errorMessage = $message;
        $this->completedAt = new DateTimeImmutable();
    }

    public function markCancelled(): void
    {
        $this->status = CatalogRunStatus::Cancelled->value;
        $this->completedAt = new DateTimeImmutable();
    }

    public function getPageCount(): ?int
    {
        return $this->pageCount;
    }

    public function getByteSize(): ?int
    {
        return $this->byteSize;
    }

    public function getItemCount(): ?int
    {
        return $this->itemCount;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getStartedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }
}
