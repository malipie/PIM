<?php

declare(strict_types=1);

namespace App\Dashboard\Domain\Entity;

use App\Shared\Application\TenantScoped;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * DASH-05 (#2257, ADR-0026) — one row per tenant per day with the dashboard
 * KPI aggregates. Written by `pim:dashboard:snapshot` (daily maintenance
 * schedule); read by DashboardSummaryQuery to compute honest deltas
 * (current value minus the snapshot at the 7d/30d horizon) and by the
 * completeness-drop alert detector (DASH-09).
 */
class DashboardSnapshot implements TenantScoped
{
    private Uuid $id;

    private ?Tenant $tenant = null;

    private DateTimeImmutable $snapshotDate;

    private int $productsTotal;

    private int $publishReadyCount;

    private int $avgCompletenessPct;

    /** @var array<string, array{avgPct: int, readyCount: int}> */
    private array $perChannel;

    private DateTimeImmutable $createdAt;

    /**
     * @param array<string, array{avgPct: int, readyCount: int}> $perChannel
     */
    public function __construct(
        DateTimeImmutable $snapshotDate,
        int $productsTotal,
        int $publishReadyCount,
        int $avgCompletenessPct,
        array $perChannel,
    ) {
        $this->id = Uuid::v7();
        $this->snapshotDate = $snapshotDate;
        $this->productsTotal = $productsTotal;
        $this->publishReadyCount = $publishReadyCount;
        $this->avgCompletenessPct = $avgCompletenessPct;
        $this->perChannel = $perChannel;
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
            throw new LogicException('Tenant already assigned to this snapshot.');
        }
        $this->tenant = $tenant;
    }

    public function getSnapshotDate(): DateTimeImmutable
    {
        return $this->snapshotDate;
    }

    public function getProductsTotal(): int
    {
        return $this->productsTotal;
    }

    public function getPublishReadyCount(): int
    {
        return $this->publishReadyCount;
    }

    public function getAvgCompletenessPct(): int
    {
        return $this->avgCompletenessPct;
    }

    /**
     * @return array<string, array{avgPct: int, readyCount: int}>
     */
    public function getPerChannel(): array
    {
        return $this->perChannel;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
