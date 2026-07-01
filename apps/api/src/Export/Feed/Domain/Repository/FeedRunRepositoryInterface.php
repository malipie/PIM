<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Repository;

use App\Export\Feed\Domain\Entity\FeedRun;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

interface FeedRunRepositoryInterface
{
    public function save(FeedRun $run): void;

    public function findById(Uuid $id): ?FeedRun;

    /**
     * Most recent runs of a feed, newest first.
     *
     * @return list<FeedRun>
     */
    public function findByFeedProfile(Uuid $feedProfileId, int $limit = 50): array;

    /**
     * Keyset page of runs, newest first (XMLF-P4-03 monitor). FeedRun ids are
     * UUIDv7, so ordering by id IS ordering by started_at — the cursor is the
     * last-seen run id. `$health` filters the DISPLAY status: success (done,
     * no warnings), warning (done with warnings), error; null = everything.
     *
     * @return list<FeedRun>
     */
    public function findPage(?Uuid $feedProfileId, ?string $health, ?Uuid $cursor, int $limit): array;

    /**
     * Tenant-wide 24h health KPI for the monitor tiles (XMLF-P4-03):
     * regeneration count, skipped sum, error count + the most recent error.
     *
     * @return array{regenerations_24h: int, skipped_24h: int, errors_24h: int, last_error: array{run_id: string, feed_id: string, message: string|null}|null}
     */
    public function kpi24h(Tenant $tenant, DateTimeImmutable $now): array;
}
