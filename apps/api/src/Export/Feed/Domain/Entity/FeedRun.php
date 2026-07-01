<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Entity;

use App\Export\Feed\Domain\Enum\FeedRunStatus;
use App\Export\Feed\Domain\Enum\FeedRunTrigger;
use App\Shared\Application\TenantScoped;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * Audit of a single feed regeneration (ADR-0023 §6.2, XMLF-P1-02) — the
 * feed-world analogue of {@see \App\Export\Domain\Entity\ExportSession}. On
 * success its file becomes the cached artifact the public URL serves
 * (XMLF-P3-05). Tenant-scoped with Postgres RLS.
 */
class FeedRun extends AggregateRoot implements TenantScoped
{
    private Uuid $id;

    private ?Tenant $tenant = null;

    /** Bare uuid → {@see FeedProfile} (DB-level FK, ON DELETE CASCADE). */
    private Uuid $feedProfileId;

    private string $trigger;

    private string $status;

    private int $itemCount = 0;

    private int $skippedCount = 0;

    private int $warningCount = 0;

    private ?string $filePath = null;

    private ?int $fileSizeBytes = null;

    private ?int $durationMs = null;

    private ?string $errorMessage = null;

    private DateTimeImmutable $startedAt;

    private ?DateTimeImmutable $completedAt = null;

    public function __construct(
        Uuid $feedProfileId,
        FeedRunTrigger $trigger,
        FeedRunStatus $status = FeedRunStatus::Pending,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->feedProfileId = $feedProfileId;
        $this->trigger = $trigger->value;
        $this->status = $status->value;
        $this->startedAt = new DateTimeImmutable();
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

    public function getFeedProfileId(): Uuid
    {
        return $this->feedProfileId;
    }

    public function getTrigger(): FeedRunTrigger
    {
        return FeedRunTrigger::from($this->trigger);
    }

    public function getStatus(): FeedRunStatus
    {
        return FeedRunStatus::from($this->status);
    }

    public function markRunning(): void
    {
        $this->status = FeedRunStatus::Running->value;
    }

    public function markDone(int $itemCount, int $skippedCount, int $warningCount, string $filePath, int $fileSizeBytes, int $durationMs): void
    {
        $this->status = FeedRunStatus::Done->value;
        $this->itemCount = $itemCount;
        $this->skippedCount = $skippedCount;
        $this->warningCount = $warningCount;
        $this->filePath = $filePath;
        $this->fileSizeBytes = $fileSizeBytes;
        $this->durationMs = $durationMs;
        $this->completedAt = new DateTimeImmutable();
    }

    public function markError(string $message): void
    {
        $this->status = FeedRunStatus::Error->value;
        $this->errorMessage = $message;
        $this->completedAt = new DateTimeImmutable();
    }

    public function markCancelled(): void
    {
        $this->status = FeedRunStatus::Cancelled->value;
        $this->completedAt = new DateTimeImmutable();
    }

    public function getItemCount(): int
    {
        return $this->itemCount;
    }

    public function getSkippedCount(): int
    {
        return $this->skippedCount;
    }

    public function getWarningCount(): int
    {
        return $this->warningCount;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function getFileSizeBytes(): ?int
    {
        return $this->fileSizeBytes;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getStartedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }
}
