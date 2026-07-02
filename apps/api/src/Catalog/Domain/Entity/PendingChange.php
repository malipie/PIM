<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Entity;

use App\Catalog\Contracts\PendingChanges\PendingChangeStatus;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use App\Shared\Application\TenantScoped;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P0-03 (#1946) — one proposed change awaiting a human decision
 * (ADR-0024 c, single approval gate).
 *
 * This is a CORE hook, not agent code: the approval inbox ("what awaits
 * my decision") lives in the product independently of the agent — the
 * agent is just one producer. Rows are written and transitioned only
 * through App\Catalog\Contracts\PendingChanges\PendingChangesPort.
 *
 * For `changeType=value`, `before`/`after` hold value envelopes per
 * docs/api/jsonb-schemas.md. `costUsd`/`tokens` are optional per-row
 * producer telemetry (the agent aggregates real cost on agent_runs).
 */
class PendingChange implements TenantScoped
{
    private Uuid $id;
    private ?Tenant $tenant = null;
    private Uuid $batchId;
    private string $provenance;
    private PendingChangeType $changeType;
    private PendingChangeStatus $status = PendingChangeStatus::Pending;
    private ?Uuid $targetObjectId;
    private ?string $attributeCode;
    private ?string $scopeLocale;
    private ?string $scopeChannel;

    /** @var array<string, mixed>|null */
    private ?array $before;

    /** @var array<string, mixed>|null */
    private ?array $after;

    /** @var array<string, mixed>|null */
    private ?array $meta;

    private ?string $costUsd = null;
    private ?int $tokens = null;
    private DateTimeImmutable $createdAt;
    private ?DateTimeImmutable $decidedAt = null;

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        Uuid $batchId,
        string $provenance,
        PendingChangeType $changeType,
        ?Uuid $targetObjectId = null,
        ?string $attributeCode = null,
        ?string $scopeLocale = null,
        ?string $scopeChannel = null,
        ?array $before = null,
        ?array $after = null,
        ?array $meta = null,
    ) {
        $this->id = Uuid::v7();
        $this->batchId = $batchId;
        $this->provenance = $provenance;
        $this->changeType = $changeType;
        $this->targetObjectId = $targetObjectId;
        $this->attributeCode = $attributeCode;
        $this->scopeLocale = $scopeLocale;
        $this->scopeChannel = $scopeChannel;
        $this->before = $before;
        $this->after = $after;
        $this->meta = $meta;
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
        if (null !== $this->tenant) {
            throw new LogicException('Tenant already assigned.');
        }
        $this->tenant = $tenant;
    }

    public function getBatchId(): Uuid
    {
        return $this->batchId;
    }

    public function getProvenance(): string
    {
        return $this->provenance;
    }

    public function getChangeType(): PendingChangeType
    {
        return $this->changeType;
    }

    public function getStatus(): PendingChangeStatus
    {
        return $this->status;
    }

    public function getTargetObjectId(): ?Uuid
    {
        return $this->targetObjectId;
    }

    public function getAttributeCode(): ?string
    {
        return $this->attributeCode;
    }

    public function getScopeLocale(): ?string
    {
        return $this->scopeLocale;
    }

    public function getScopeChannel(): ?string
    {
        return $this->scopeChannel;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBefore(): ?array
    {
        return $this->before;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAfter(): ?array
    {
        return $this->after;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMeta(): ?array
    {
        return $this->meta;
    }

    public function getCostUsd(): ?string
    {
        return $this->costUsd;
    }

    public function getTokens(): ?int
    {
        return $this->tokens;
    }

    public function setProducerTelemetry(?string $costUsd, ?int $tokens): void
    {
        $this->costUsd = $costUsd;
        $this->tokens = $tokens;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDecidedAt(): ?DateTimeImmutable
    {
        return $this->decidedAt;
    }
}
