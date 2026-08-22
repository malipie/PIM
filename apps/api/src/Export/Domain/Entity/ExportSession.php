<?php

declare(strict_types=1);

namespace App\Export\Domain\Entity;

use App\Export\Domain\Enum\ExportEncoding;
use App\Export\Domain\Enum\ExportEntityType;
use App\Export\Domain\Enum\ExportFormat;
use App\Export\Domain\Enum\ExportSource;
use App\Export\Domain\Enum\ExportStatus;
use App\Export\Domain\Enum\ExportTargetScope;
use App\Shared\Application\TenantScoped;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One execution of an export job (PRD §5.1).
 *
 * Sync path (<100 rows, EXP-05) materializes the row as `done` in a
 * single transaction. Async path (>=100 rows, EXP-06) transitions
 * pending → running → done/error and emits Mercure progress events.
 *
 * The `filter_snapshot` JSONB preserves the active filter at export
 * time so EXP-08 `POST /sessions/{id}/rerun` can replay the exact set
 * — even if the underlying filter UI state has moved on.
 *
 * Tenant isolation: `tenant_id NOT NULL`, stamped by
 * {@see \App\Shared\Infrastructure\Doctrine\EventListener\TenantAssignmentListener}.
 * Doctrine TenantFilter applies the WHERE clause on every query.
 */
class ExportSession extends AggregateRoot implements TenantScoped
{
    private Uuid $id;

    private ?Tenant $tenant = null;

    /** Bare uuid to avoid cross-BC coupling with Identity\User. */
    private Uuid $userId;

    private ?ExportProfile $profile = null;

    private string $source;

    private string $format;

    private ?string $encoding = null;

    private string $targetScope;

    private string $entityType;

    /** Bare uuid → Catalog\ObjectType; the FK lives at the DB level only (cross-BC decoupling). */
    private ?Uuid $objectTypeId = null;

    /** @var array<string, mixed>|null */
    private ?array $filterSnapshot = null;

    /** @var list<string>|null */
    private ?array $selectedObjectIds = null;

    /** @var list<string> */
    private array $selectedColumns;

    /** @var list<string>|null */
    private ?array $locales = null;

    /** @var list<string>|null */
    private ?array $channels = null;

    private bool $includeVariants = true;

    #[Assert\PositiveOrZero]
    private int $targetCount = 0;

    private int $successCount = 0;

    private ?string $filePath = null;

    private ?int $fileSizeBytes = null;

    private ?int $durationMs = null;

    private string $status = ExportStatus::Pending->value;

    private ?string $errorMessage = null;

    private DateTimeImmutable $startedAt;

    private ?DateTimeImmutable $completedAt = null;

    /**
     * @param list<string>              $selectedColumns
     * @param list<string>|null         $selectedObjectIds
     * @param array<string, mixed>|null $filterSnapshot
     * @param list<string>|null         $locales
     * @param list<string>|null         $channels
     */
    public function __construct(
        Uuid $userId,
        ExportSource $source,
        ExportFormat $format,
        ExportTargetScope $targetScope,
        array $selectedColumns,
        ?ExportProfile $profile = null,
        ?ExportEncoding $encoding = null,
        ?array $filterSnapshot = null,
        ?array $selectedObjectIds = null,
        ?array $locales = null,
        ?array $channels = null,
        bool $includeVariants = true,
        ExportEntityType $entityType = ExportEntityType::Product,
        ?Uuid $objectTypeId = null,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->userId = $userId;
        $this->source = $source->value;
        $this->format = $format->value;
        $this->targetScope = $targetScope->value;
        $this->entityType = $entityType->value;
        $this->objectTypeId = $objectTypeId;
        $this->selectedColumns = $selectedColumns;
        $this->profile = $profile;
        $this->encoding = $encoding?->value;
        $this->filterSnapshot = $filterSnapshot;
        $this->selectedObjectIds = $selectedObjectIds;
        $this->locales = $locales;
        $this->channels = $channels;
        $this->includeVariants = $includeVariants;
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

    /**
     * AUD-015 (#1632) — re-attach the SAME tenant as a managed instance after an
     * EntityManager::clear() detached the original (the streaming export clears
     * the EM between keyset pages). Unlike {@see assignTenant()} this is a
     * managed-state refresh, not a reassignment: it refuses a DIFFERENT tenant
     * so the isolation invariant still holds.
     */
    public function rebindTenant(Tenant $tenant): void
    {
        if (null !== $this->tenant && !$this->tenant->getId()->equals($tenant->getId())) {
            throw new LogicException('rebindTenant() cannot switch the session to a different tenant.');
        }
        $this->tenant = $tenant;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getProfile(): ?ExportProfile
    {
        return $this->profile;
    }

    public function getSource(): ExportSource
    {
        return ExportSource::from($this->source);
    }

    public function getFormat(): ExportFormat
    {
        return ExportFormat::from($this->format);
    }

    public function getEncoding(): ?ExportEncoding
    {
        return null === $this->encoding ? null : ExportEncoding::from($this->encoding);
    }

    public function getTargetScope(): ExportTargetScope
    {
        return ExportTargetScope::from($this->targetScope);
    }

    public function getEntityType(): ExportEntityType
    {
        return ExportEntityType::from($this->entityType);
    }

    public function getObjectTypeId(): ?Uuid
    {
        return $this->objectTypeId;
    }

    /** @return array<string, mixed>|null */
    public function getFilterSnapshot(): ?array
    {
        return $this->filterSnapshot;
    }

    /** @return list<string>|null */
    public function getSelectedObjectIds(): ?array
    {
        return $this->selectedObjectIds;
    }

    /** @return list<string> */
    public function getSelectedColumns(): array
    {
        return $this->selectedColumns;
    }

    /** @return list<string>|null */
    public function getLocales(): ?array
    {
        return $this->locales;
    }

    /** @return list<string>|null */
    public function getChannels(): ?array
    {
        return $this->channels;
    }

    public function includesVariants(): bool
    {
        return $this->includeVariants;
    }

    public function getTargetCount(): int
    {
        return $this->targetCount;
    }

    public function setTargetCount(int $targetCount): void
    {
        if ($targetCount < 0) {
            throw new LogicException('Target count cannot be negative.');
        }
        $this->targetCount = $targetCount;
    }

    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    public function incrementSuccess(int $by = 1): void
    {
        $this->successCount += $by;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function setFilePath(?string $filePath): void
    {
        $this->filePath = $filePath;
    }

    public function getFileSizeBytes(): ?int
    {
        return $this->fileSizeBytes;
    }

    public function setFileSizeBytes(?int $bytes): void
    {
        $this->fileSizeBytes = $bytes;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function getStatus(): ExportStatus
    {
        return ExportStatus::from($this->status);
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

    public function markRunning(): void
    {
        $this->ensureTransitionable([ExportStatus::Pending]);
        $this->status = ExportStatus::Running->value;
    }

    public function markDone(int $successCount, string $filePath, int $fileSizeBytes): void
    {
        $this->ensureTransitionable([ExportStatus::Pending, ExportStatus::Running]);
        $this->status = ExportStatus::Done->value;
        $this->successCount = $successCount;
        $this->filePath = $filePath;
        $this->fileSizeBytes = $fileSizeBytes;
        $this->completedAt = new DateTimeImmutable();
        $this->durationMs = ($this->completedAt->getTimestamp() - $this->startedAt->getTimestamp()) * 1000;
    }

    public function markCancelled(): void
    {
        $this->ensureTransitionable([ExportStatus::Pending, ExportStatus::Running]);
        $this->status = ExportStatus::Cancelled->value;
        $this->completedAt = new DateTimeImmutable();
        $this->durationMs = ($this->completedAt->getTimestamp() - $this->startedAt->getTimestamp()) * 1000;
    }

    public function markError(string $message): void
    {
        $this->ensureTransitionable([ExportStatus::Pending, ExportStatus::Running]);
        $this->status = ExportStatus::Error->value;
        $this->errorMessage = $message;
        $this->completedAt = new DateTimeImmutable();
        $this->durationMs = ($this->completedAt->getTimestamp() - $this->startedAt->getTimestamp()) * 1000;
    }

    /**
     * #2945 — the run produced every row but the artifact never reached
     * storage, so there is nothing to download.
     *
     * A separate transition from {@see markError} because `done` is a legal
     * starting point here: the rows ARE counted and the duration IS real.
     * What failed is the hand-off of the file. Leaving such a session as a
     * plain success — which is what the handler used to do, to avoid a 500 —
     * gives the operator a green row with a dead download button and no way
     * to tell why. The file path is cleared along with it: a path into a
     * worker's `/tmp` is not something any other container can serve.
     */
    public function markFileUnavailable(string $message): void
    {
        $this->ensureTransitionable([ExportStatus::Pending, ExportStatus::Running, ExportStatus::Done]);
        $this->status = ExportStatus::Error->value;
        $this->errorMessage = $message;
        $this->filePath = null;
        $this->completedAt ??= new DateTimeImmutable();
        $this->durationMs ??= ($this->completedAt->getTimestamp() - $this->startedAt->getTimestamp()) * 1000;
    }

    public function isSelfOwnedBy(Uuid $userId): bool
    {
        return $this->userId->equals($userId);
    }

    /**
     * @param list<ExportStatus> $allowed
     */
    private function ensureTransitionable(array $allowed): void
    {
        $current = $this->getStatus();
        foreach ($allowed as $status) {
            if ($status === $current) {
                return;
            }
        }
        throw new LogicException(\sprintf(
            'Cannot transition export session %s from "%s".',
            $this->id->toRfc4122(),
            $this->status,
        ));
    }
}
