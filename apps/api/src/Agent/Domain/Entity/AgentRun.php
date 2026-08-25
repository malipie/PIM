<?php

declare(strict_types=1);

namespace App\Agent\Domain\Entity;

use App\Agent\Domain\AgentRunStatus;
use App\Agent\Domain\AgentRunSurface;
use App\Shared\Application\TenantScoped;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P1-01 (#1953) — one agent run: intent + view context in,
 * plan/proposal out, human decision, optional rollback (PRD 5.8).
 *
 * The run always acts within the initiating user's permissions
 * (user_id). `context` carries the view context (objectType, filter
 * DSL, selection, locale/channel) verbatim. Cost/token counters are
 * accumulated per Anthropic call; `pending_change_batch_id` links the
 * materialized proposal (P0-03), `bulk_operation_id` the post-approval
 * BulkSession used for rollback (P3-04).
 */
class AgentRun implements TenantScoped
{
    private Uuid $id;
    private ?Tenant $tenant = null;
    private Uuid $userId;
    private AgentRunSurface $surface;
    private string $intent;

    /** @var array<string, mixed> */
    private array $context;

    private AgentRunStatus $status = AgentRunStatus::Planning;
    private ?string $model = null;
    private ?Uuid $pendingChangeBatchId = null;
    private ?Uuid $bulkOperationId = null;
    private ?int $affectedCount = null;
    private int $tokensInput = 0;
    private int $tokensOutput = 0;
    private int $cacheReadTokens = 0;
    private int $cacheCreationTokens = 0;
    private int $llmCalls = 0;
    private int $llmDurationMs = 0;
    private int $llmTtftMs = 0;
    private ?int $queueDelayMs = null;
    private string $costUsd = '0';
    private ?string $errorMessage = null;
    private DateTimeImmutable $startedAt;
    private ?DateTimeImmutable $approvedAt = null;
    private ?Uuid $approvedBy = null;
    private ?DateTimeImmutable $completedAt = null;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        Uuid $userId,
        AgentRunSurface $surface,
        string $intent,
        array $context = [],
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->userId = $userId;
        $this->surface = $surface;
        $this->intent = $intent;
        $this->context = $context;
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
            throw new LogicException('Tenant already assigned.');
        }
        $this->tenant = $tenant;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getSurface(): AgentRunSurface
    {
        return $this->surface;
    }

    public function getIntent(): string
    {
        return $this->intent;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    public function getStatus(): AgentRunStatus
    {
        return $this->status;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(string $model): void
    {
        $this->model = $model;
    }

    public function getPendingChangeBatchId(): ?Uuid
    {
        return $this->pendingChangeBatchId;
    }

    public function getBulkOperationId(): ?Uuid
    {
        return $this->bulkOperationId;
    }

    public function getAffectedCount(): ?int
    {
        return $this->affectedCount;
    }

    public function getTokensInput(): int
    {
        return $this->tokensInput;
    }

    public function getTokensOutput(): int
    {
        return $this->tokensOutput;
    }

    public function getCacheReadTokens(): int
    {
        return $this->cacheReadTokens;
    }

    public function getCacheCreationTokens(): int
    {
        return $this->cacheCreationTokens;
    }

    public function getLlmCalls(): int
    {
        return $this->llmCalls;
    }

    public function getLlmDurationMs(): int
    {
        return $this->llmDurationMs;
    }

    public function getLlmTtftMs(): int
    {
        return $this->llmTtftMs;
    }

    public function getQueueDelayMs(): ?int
    {
        return $this->queueDelayMs;
    }

    public function getCostUsd(): string
    {
        return $this->costUsd;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getStartedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getApprovedAt(): ?DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function getApprovedBy(): ?Uuid
    {
        return $this->approvedBy;
    }

    public function getCompletedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    /**
     * Accumulate Anthropic usage after each model call (P1-03).
     * Cost is summed in integer micro-dollars (no bcmath on the image;
     * float rounding at 6 decimals would drift over many calls).
     */
    public function addUsage(int $tokensInput, int $tokensOutput, string $costUsd): void
    {
        $this->tokensInput += $tokensInput;
        $this->tokensOutput += $tokensOutput;

        $microSum = (int) round((float) $this->costUsd * 1_000_000) + (int) round((float) $costUsd * 1_000_000);
        $this->costUsd = number_format($microSum / 1_000_000, 6, '.', '');
    }

    public function recordDequeued(int $enqueuedAtMs, ?int $dequeuedAtMs = null): void
    {
        $dequeuedAtMs ??= (int) floor(microtime(true) * 1000);
        $this->queueDelayMs = max(0, $dequeuedAtMs - $enqueuedAtMs);
    }

    public function recordLlmCall(
        int $durationMs,
        int $ttftMs,
        int $cacheReadTokens,
        int $cacheCreationTokens,
    ): void {
        ++$this->llmCalls;
        $this->llmDurationMs += max(0, $durationMs);
        $this->llmTtftMs += max(0, $ttftMs);
        $this->cacheReadTokens += max(0, $cacheReadTokens);
        $this->cacheCreationTokens += max(0, $cacheCreationTokens);
    }

    public function markAwaitingInput(): void
    {
        $this->assertNotTerminal();
        $this->status = AgentRunStatus::AwaitingInput;
    }

    public function resumePlanning(): void
    {
        $this->assertNotTerminal();
        $this->status = AgentRunStatus::Planning;
    }

    public function markAwaitingApproval(Uuid $pendingChangeBatchId, int $affectedCount): void
    {
        $this->assertNotTerminal();
        $this->status = AgentRunStatus::AwaitingApproval;
        $this->pendingChangeBatchId = $pendingChangeBatchId;
        $this->affectedCount = $affectedCount;
    }

    public function markCommitting(Uuid $approvedBy): void
    {
        if (AgentRunStatus::AwaitingApproval !== $this->status) {
            throw new LogicException(\sprintf('Cannot commit a run in status "%s".', $this->status->value));
        }
        $this->status = AgentRunStatus::Committing;
        $this->approvedAt = new DateTimeImmutable();
        $this->approvedBy = $approvedBy;
    }

    public function markDone(Uuid $bulkOperationId): void
    {
        $this->status = AgentRunStatus::Done;
        $this->bulkOperationId = $bulkOperationId;
        $this->completedAt = new DateTimeImmutable();
    }

    public function markRejected(): void
    {
        $this->status = AgentRunStatus::Rejected;
        $this->completedAt = new DateTimeImmutable();
    }

    public function markCancelled(): void
    {
        $this->status = AgentRunStatus::Cancelled;
        $this->completedAt = new DateTimeImmutable();
    }

    public function markError(string $message): void
    {
        $this->status = AgentRunStatus::Error;
        $this->errorMessage = $message;
        $this->completedAt = new DateTimeImmutable();
    }

    public function markRolledBack(): void
    {
        if (AgentRunStatus::Done !== $this->status) {
            throw new LogicException(\sprintf('Only a done run can be rolled back; got "%s".', $this->status->value));
        }
        $this->status = AgentRunStatus::RolledBack;
    }

    private function assertNotTerminal(): void
    {
        if ($this->status->isTerminal()) {
            throw new LogicException(\sprintf('Run is terminal ("%s").', $this->status->value));
        }
    }
}
