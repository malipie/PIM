<?php

declare(strict_types=1);

namespace App\Integration\Generic\Domain\Entity;

use App\Integration\Generic\Domain\Enum\MappingDirection;
use App\Shared\Application\TenantScoped;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A 1:1 mapping between a PIM target and a remote field path (ADR-0022, epic
 * APIC, ticket APIC-P2-07).
 *
 * `pimTarget` names the PIM side — an attribute code or a system field
 * (sku/name/status/category…); `remoteFieldPath` is the external JSONPath. The
 * mapping belongs to a {@see Connection} and is versioned so it can be reused
 * across sync bindings (the optional `bindingId` links it to a SyncBinding once
 * that lands in APIC-P3-01 — kept loose here, no FK). `direction` and
 * `isMatchKey` drive the sync engines: the match key identifies the row to
 * upsert, the direction gates inbound/outbound application.
 *
 * `TenantScoped` + Postgres RLS isolate every mapping to its tenant. Type
 * compatibility between the PIM target and the remote field is validated at the
 * API edge (APIC-P2-08), not in this domain entity.
 */
class FieldMapping extends AggregateRoot implements TenantScoped
{
    private Uuid $id;

    private ?Tenant $tenant = null;

    private Connection $connection;

    /** Loose link to a future SyncBinding (APIC-P3-01); no FK until it exists. */
    private ?Uuid $bindingId = null;

    /**
     * Optional endpoint scope (#2634): RPC-style APIs shape the payload per
     * operation (BaseLinker's addInventoryProduct ≠ updateInventoryProductsStock),
     * so a mapping may target one endpoint only. Null = applies to every
     * endpoint of the connection (the pre-#2634 behaviour). Deleting the
     * endpoint deletes its scoped mappings (DB cascade) — an orphaned scope
     * must not silently widen back to all endpoints.
     */
    private ?RemoteEndpoint $endpoint = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $pimTarget;

    #[Assert\NotBlank]
    #[Assert\Length(max: 512)]
    private string $remoteFieldPath;

    private string $direction = MappingDirection::Inbound->value;

    private bool $isMatchKey = false;

    #[Assert\Positive]
    private int $version = 1;

    private DateTimeImmutable $createdAt;

    private DateTimeImmutable $updatedAt;

    public function __construct(
        Connection $connection,
        string $pimTarget,
        string $remoteFieldPath,
        MappingDirection $direction = MappingDirection::Inbound,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->connection = $connection;
        $this->pimTarget = $pimTarget;
        $this->remoteFieldPath = $remoteFieldPath;
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

    public function getEndpoint(): ?RemoteEndpoint
    {
        return $this->endpoint;
    }

    public function getEndpointId(): ?Uuid
    {
        return $this->endpoint?->getId();
    }

    public function setEndpoint(?RemoteEndpoint $endpoint): void
    {
        $this->endpoint = $endpoint;
        $this->touch();
    }

    /** Whether this mapping participates in a sync run using the given endpoint. */
    public function appliesToEndpoint(Uuid $endpointId): bool
    {
        return null === $this->endpoint || $this->endpoint->getId()->equals($endpointId);
    }

    public function getBindingId(): ?Uuid
    {
        return $this->bindingId;
    }

    public function bindTo(?Uuid $bindingId): void
    {
        $this->bindingId = $bindingId;
        $this->touch();
    }

    public function getPimTarget(): string
    {
        return $this->pimTarget;
    }

    public function setPimTarget(string $pimTarget): void
    {
        $this->pimTarget = $pimTarget;
        $this->touch();
    }

    public function getRemoteFieldPath(): string
    {
        return $this->remoteFieldPath;
    }

    public function setRemoteFieldPath(string $remoteFieldPath): void
    {
        $this->remoteFieldPath = $remoteFieldPath;
        $this->touch();
    }

    public function getDirection(): MappingDirection
    {
        return MappingDirection::from($this->direction);
    }

    public function setDirection(MappingDirection $direction): void
    {
        $this->direction = $direction->value;
        $this->touch();
    }

    public function isMatchKey(): bool
    {
        return $this->isMatchKey;
    }

    /**
     * Serializer-facing alias so the read projection exposes the flag as
     * `isMatchKey` (mirrors the input DTO), per the ObjectType convention.
     */
    public function getIsMatchKey(): bool
    {
        return $this->isMatchKey;
    }

    public function setMatchKey(bool $isMatchKey): void
    {
        $this->isMatchKey = $isMatchKey;
        $this->touch();
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /** Bumps the version when the mapping is edited so reuse can pin a revision. */
    public function bumpVersion(): void
    {
        ++$this->version;
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
