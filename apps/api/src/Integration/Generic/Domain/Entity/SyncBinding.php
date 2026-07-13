<?php

declare(strict_types=1);

namespace App\Integration\Generic\Domain\Entity;

use App\Integration\Generic\Domain\Enum\ConflictPolicy;
use App\Integration\Generic\Domain\Enum\SyncDirection;
use App\Shared\Application\TenantScoped;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The heart of a sync: what (ObjectType) is synced where (Connection endpoints),
 * how (direction + conflict policy + match key) and how often (cron schedule) —
 * ADR-0022, epic APIC, ticket APIC-P3-01.
 *
 * `objectTypeId` is a validated loose reference, not a Doctrine/DB FK: the
 * target ObjectType lives in the Catalog context and ADR-0022 keeps cross-BC
 * coupling to Contracts only, so no migration-level FK ties Integration to the
 * Catalog schema (the binding API validates the id exists). The read/write
 * endpoints and the connection are same-context FKs.
 *
 * `cursor` is the delta-sync state envelope (`{field, type, state}`) maintained
 * by the cursor manager (APIC-P3-03). `TenantScoped` + Postgres RLS isolate
 * every binding to its tenant.
 */
class SyncBinding extends AggregateRoot implements TenantScoped
{
    private Uuid $id;

    private ?Tenant $tenant = null;

    private Connection $connection;

    private Uuid $objectTypeId;

    private ?RemoteEndpoint $readEndpoint = null;

    private ?RemoteEndpoint $writeEndpoint = null;

    private string $direction = SyncDirection::Inbound->value;

    /** Cron expression for scheduled runs; null = manual-only. */
    #[Assert\Length(max: 255)]
    private ?string $schedule = null;

    /**
     * Delta-sync cursor envelope `{field, type, state}`; null until the first
     * run persists one (APIC-P3-03).
     *
     * @var array<string, mixed>|null
     */
    private ?array $cursor = null;

    private string $conflictPolicy = ConflictPolicy::Lww->value;

    /**
     * #2549 — rule-based OUTBOUND scope: a FilterDsl snapshot narrowing which
     * objects PIM sends to the remote (e.g. only `status = published`). null /
     * empty = send every object of the type (backward-compat). Applies only to
     * the write (outbound) flow; inbound reads ignore it. Compiled at run time
     * via the Catalog FilterDslResolver in the Export reader, so a malformed
     * DSL fails the run rather than the save (the FE builder emits valid DSL).
     *
     * @var array<string, mixed>|null
     */
    private ?array $outboundFilter = null;

    /** The PIM target acting as the upsert match key; null until mappings are set. */
    #[Assert\Length(max: 255)]
    private ?string $matchKeyMapping = null;

    private bool $enabled = true;

    /**
     * When the scheduler next fires this binding; null = manual-only (no cron)
     * or not yet computed. Maintained by the schedule dispatcher (APIC-P3-09),
     * jittered per tenant so concurrent bindings don't stampede the same remote.
     */
    private ?DateTimeImmutable $nextRun = null;

    private DateTimeImmutable $createdAt;

    private DateTimeImmutable $updatedAt;

    public function __construct(
        Connection $connection,
        Uuid $objectTypeId,
        SyncDirection $direction = SyncDirection::Inbound,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->connection = $connection;
        $this->objectTypeId = $objectTypeId;
        $this->direction = $direction->value;
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
        if (null !== $this->tenant) {
            throw new LogicException('Tenant is already assigned and cannot be reassigned.');
        }

        $this->tenant = $tenant;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function getConnectionId(): Uuid
    {
        return $this->connection->getId();
    }

    public function getObjectTypeId(): Uuid
    {
        return $this->objectTypeId;
    }

    public function setObjectTypeId(Uuid $objectTypeId): void
    {
        $this->objectTypeId = $objectTypeId;
        $this->touch();
    }

    public function getReadEndpoint(): ?RemoteEndpoint
    {
        return $this->readEndpoint;
    }

    public function getReadEndpointId(): ?Uuid
    {
        return $this->readEndpoint?->getId();
    }

    public function getWriteEndpointId(): ?Uuid
    {
        return $this->writeEndpoint?->getId();
    }

    public function setReadEndpoint(?RemoteEndpoint $readEndpoint): void
    {
        $this->readEndpoint = $readEndpoint;
        $this->touch();
    }

    public function getWriteEndpoint(): ?RemoteEndpoint
    {
        return $this->writeEndpoint;
    }

    public function setWriteEndpoint(?RemoteEndpoint $writeEndpoint): void
    {
        $this->writeEndpoint = $writeEndpoint;
        $this->touch();
    }

    public function getDirection(): SyncDirection
    {
        return SyncDirection::from($this->direction);
    }

    public function setDirection(SyncDirection $direction): void
    {
        $this->direction = $direction->value;
        $this->touch();
    }

    public function getSchedule(): ?string
    {
        return $this->schedule;
    }

    public function setSchedule(?string $schedule): void
    {
        $this->schedule = $schedule;
        $this->touch();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCursor(): ?array
    {
        return $this->cursor;
    }

    /**
     * @param array<string, mixed>|null $cursor
     */
    public function setCursor(?array $cursor): void
    {
        $this->cursor = $cursor;
        $this->touch();
    }

    public function getConflictPolicy(): ConflictPolicy
    {
        return ConflictPolicy::from($this->conflictPolicy);
    }

    public function setConflictPolicy(ConflictPolicy $conflictPolicy): void
    {
        $this->conflictPolicy = $conflictPolicy->value;
        $this->touch();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOutboundFilter(): ?array
    {
        return $this->outboundFilter;
    }

    /**
     * @param array<string, mixed>|null $outboundFilter
     */
    public function setOutboundFilter(?array $outboundFilter): void
    {
        $this->outboundFilter = $outboundFilter;
        $this->touch();
    }

    public function getMatchKeyMapping(): ?string
    {
        return $this->matchKeyMapping;
    }

    public function setMatchKeyMapping(?string $matchKeyMapping): void
    {
        $this->matchKeyMapping = $matchKeyMapping;
        $this->touch();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Serializer-facing alias so the read projection exposes the flag as
     * `isEnabled`, per the ObjectType convention.
     */
    public function getIsEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        $this->touch();
    }

    public function getNextRun(): ?DateTimeImmutable
    {
        return $this->nextRun;
    }

    /**
     * Set by the schedule dispatcher only. Deliberately does NOT `touch()`
     * `updatedAt`: the next-run time is recomputed after every fire, and that
     * housekeeping must not look like a user edit of the binding.
     */
    public function setNextRun(?DateTimeImmutable $nextRun): void
    {
        $this->nextRun = $nextRun;
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
