<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Repository;

use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Telemetry\FeedPullAggregate;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Pull telemetry for the public feed URL (XMLF-P3-06, ADR-0023 §6.9).
 *
 * Backed by `feed_pull_stats` hourly counter rows (UPSERT increment — bounded
 * growth, no per-hit event rows) plus `feed_profiles.last_pulled_at`. Never
 * receives or stores the URL token — accounting is keyed by feed id, which is
 * equivalent (a feed has exactly one active token) and cannot leak a secret.
 */
interface FeedPullStatsRepositoryInterface
{
    /**
     * Count one hit on the public pull URL (200 and 304 alike — a conditional
     * revalidation still proves the crawler polls) and stamp `last_pulled_at`.
     */
    public function record(FeedProfile $feed, DateTimeImmutable $at): void;

    /**
     * 24h aggregate for one feed (`$feedId`) or the whole tenant (`null` —
     * the hub KPI strip sums every feed).
     */
    public function aggregate(Tenant $tenant, ?Uuid $feedId, DateTimeImmutable $now): FeedPullAggregate;
}
