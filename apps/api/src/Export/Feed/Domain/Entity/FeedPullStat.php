<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Entity;

use App\Shared\Application\TenantScoped;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * One hourly pull counter of a feed's public URL (XMLF-P3-06, ADR-0023 §6.9).
 *
 * The write path is a raw atomic UPSERT and the read side an aggregate
 * ({@see \App\Export\Feed\Infrastructure\Doctrine\Repository\DoctrineFeedPullStatsRepository})
 * — this entity is never persisted through the ORM. It exists so the mapping
 * layer knows the table: the test kernel builds its schema from ORM metadata
 * (`doctrine:schema:create`), and an unmapped table would simply not exist
 * there. The production DDL (plus the RLS policies and the ON DELETE CASCADE
 * FK to feed_profiles, which the mapping deliberately omits — the FeedRun
 * precedent) lives in migration Version20260701140000.
 */
class FeedPullStat implements TenantScoped
{
    private Uuid $id;

    private ?Tenant $tenant = null;

    /** Bare uuid → {@see FeedProfile} (DB-level FK, ON DELETE CASCADE). */
    private Uuid $feedId;

    /** Start of the UTC hour bucket this row counts. */
    private DateTimeImmutable $bucketStart;

    private int $pullCount = 0;

    public function __construct(Uuid $feedId, DateTimeImmutable $bucketStart, ?Uuid $id = null)
    {
        $this->id = $id ?? Uuid::v7();
        $this->feedId = $feedId;
        $this->bucketStart = $bucketStart;
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

    public function getFeedId(): Uuid
    {
        return $this->feedId;
    }

    public function getBucketStart(): DateTimeImmutable
    {
        return $this->bucketStart;
    }

    public function getPullCount(): int
    {
        return $this->pullCount;
    }
}
