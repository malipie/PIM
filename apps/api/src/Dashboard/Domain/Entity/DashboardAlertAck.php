<?php

declare(strict_types=1);

namespace App\Dashboard\Domain\Entity;

use App\Shared\Application\TenantScoped;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * DASH-09 (#2265, ADR-0026) — one acknowledged action-center fingerprint
 * per row. Alerts are aggregated on the fly (no Alert entity, operator
 * decision 2026-07-04); this is the only persisted alert state.
 */
class DashboardAlertAck implements TenantScoped
{
    private Uuid $id;

    private ?Tenant $tenant = null;

    private string $fingerprint;

    private ?Uuid $ackedBy;

    private DateTimeImmutable $ackedAt;

    public function __construct(string $fingerprint, ?Uuid $ackedBy)
    {
        $this->id = Uuid::v7();
        $this->fingerprint = $fingerprint;
        $this->ackedBy = $ackedBy;
        $this->ackedAt = new DateTimeImmutable();
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
            throw new LogicException('Tenant already assigned to this ack.');
        }
        $this->tenant = $tenant;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function getAckedBy(): ?Uuid
    {
        return $this->ackedBy;
    }

    public function getAckedAt(): DateTimeImmutable
    {
        return $this->ackedAt;
    }
}
