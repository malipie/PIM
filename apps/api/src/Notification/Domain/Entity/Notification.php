<?php

declare(strict_types=1);

namespace App\Notification\Domain\Entity;

use App\Shared\Application\TenantScoped;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * WFL-P2-02 (#2421) — one persistent in-app notification for one user.
 * Deliberately generic (type + payload JSONB): the workflow fan-out is
 * the first writer, future modules (imports, sync) reuse the rows.
 * UUIDv7 ids double as the pagination cursor (time-ordered).
 */
class Notification implements TenantScoped
{
    private Uuid $id;
    private ?Tenant $tenant = null;
    private Uuid $userId;
    private string $type;

    /** @var array<string, mixed>|null */
    private ?array $payload;

    private ?DateTimeImmutable $readAt = null;
    private DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed>|null $payload
     */
    public function __construct(Uuid $userId, string $type, ?array $payload = null)
    {
        $this->id = Uuid::v7();
        $this->userId = $userId;
        $this->type = $type;
        $this->payload = $payload;
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
            throw new LogicException('Notification rows cannot move between tenants.');
        }

        $this->tenant = $tenant;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    public function getReadAt(): ?DateTimeImmutable
    {
        return $this->readAt;
    }

    public function markRead(): void
    {
        $this->readAt ??= new DateTimeImmutable();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
