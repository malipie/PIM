<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Entity;

use App\Export\Feed\Domain\Enum\FeedRunLogLevel;
use App\Shared\Application\TenantScoped;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * One per-product / per-slot line of a feed run (ADR-0023 §6.2, XMLF-P1-02) —
 * the "feed health" trail (e.g. "SKU-123: missing g:gtin — skipped"). Carries
 * its own `tenant_id` for RLS defence in depth (the import_logs precedent,
 * IMP2-2.5), on top of the FeedRun cascade.
 */
class FeedRunLog implements TenantScoped
{
    private Uuid $id;

    private ?Tenant $tenant = null;

    /** Bare uuid → {@see FeedRun} (DB-level FK, ON DELETE CASCADE). */
    private Uuid $feedRunId;

    private string $level;

    private ?string $objectSku;

    private ?string $slot;

    private string $message;

    private DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $feedRunId,
        FeedRunLogLevel $level,
        string $message,
        ?string $objectSku = null,
        ?string $slot = null,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->feedRunId = $feedRunId;
        $this->level = $level->value;
        $this->message = $message;
        $this->objectSku = $objectSku;
        $this->slot = $slot;
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
            throw new LogicException('Tenant is already assigned and cannot be reassigned.');
        }
        $this->tenant = $tenant;
    }

    public function getFeedRunId(): Uuid
    {
        return $this->feedRunId;
    }

    public function getLevel(): FeedRunLogLevel
    {
        return FeedRunLogLevel::from($this->level);
    }

    public function getObjectSku(): ?string
    {
        return $this->objectSku;
    }

    public function getSlot(): ?string
    {
        return $this->slot;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
