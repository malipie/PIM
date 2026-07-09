<?php

declare(strict_types=1);

namespace App\Export\Catalog\Domain\Repository;

use App\Export\Catalog\Domain\Entity\CatalogRun;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

interface CatalogRunRepositoryInterface
{
    public function save(CatalogRun $run): void;

    public function findById(Uuid $id): ?CatalogRun;

    /**
     * Most recent runs of a catalog, newest first.
     *
     * @return list<CatalogRun>
     */
    public function findByCatalogProfile(Uuid $catalogProfileId, int $limit = 50): array;

    /**
     * Keyset page of runs, newest first (CPDF monitor). CatalogRun ids are
     * UUIDv7, so ordering by id IS ordering by started_at — the cursor is the
     * last-seen run id. `$health` filters the DISPLAY status: success (done),
     * error; null = everything.
     *
     * @return list<CatalogRun>
     */
    public function findPage(?Uuid $catalogProfileId, ?string $health, ?Uuid $cursor, int $limit): array;

    /**
     * Tenant-wide 24h health KPI for the monitor tiles (CPDF): regeneration
     * count, item sum, error count + the most recent error.
     *
     * @return array{regenerations_24h: int, items_24h: int, errors_24h: int, last_error: array{run_id: string, catalog_id: string, message: string|null}|null}
     */
    public function kpi24h(Tenant $tenant, DateTimeImmutable $now): array;
}
