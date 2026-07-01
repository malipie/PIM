<?php

declare(strict_types=1);

namespace App\Export\Feed\Infrastructure\Doctrine\Repository;

use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Repository\FeedPullStatsRepositoryInterface;
use App\Export\Feed\Domain\Telemetry\FeedPullAggregate;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * DBAL-backed pull telemetry (XMLF-P3-06). `feed_pull_stats` is deliberately
 * not ORM-mapped: the hot path is a single atomic UPSERT per public pull and
 * the read side is an aggregate — there is never an entity to hydrate.
 *
 * All timestamps are normalised to UTC hour buckets so the sparkline is
 * timezone-stable regardless of the PHP default timezone.
 */
final readonly class DoctrineFeedPullStatsRepository implements FeedPullStatsRepositoryInterface
{
    private const string SQL_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function record(FeedProfile $feed, DateTimeImmutable $at): void
    {
        $tenantId = $feed->getTenant()?->getId()->toRfc4122();
        if (null === $tenantId) {
            return; // Unassigned tenant cannot happen for a persisted feed.
        }
        $feedId = $feed->getId()->toRfc4122();
        $bucket = $this->hourBucket($at);

        // One counter row per feed per hour — atomic increment, no read-modify-write race.
        // tenant-safe: explicit tenant_id filter (tenant_id comes from the RLS-scoped feed row itself; the pull endpoint sets the GUC from the URL before this runs)
        $this->connection->executeStatement(
            'INSERT INTO feed_pull_stats (id, tenant_id, feed_id, bucket_start, pull_count) '
            .'VALUES (:id, :tenant, :feed, :bucket, 1) '
            .'ON CONFLICT (tenant_id, feed_id, bucket_start) '
            .'DO UPDATE SET pull_count = feed_pull_stats.pull_count + 1',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'tenant' => $tenantId,
                'feed' => $feedId,
                'bucket' => $bucket->format(self::SQL_FORMAT),
            ],
        );

        // Raw per-row UPDATE keyed by primary key — bypasses the ORM on purpose:
        // no touch() (updated_at is for config changes) and no flush of an
        // unrelated unit of work on the public serving path.
        // tenant-safe: per-row UPDATE keyed by primary key with explicit tenant_id filter
        $this->connection->executeStatement(
            'UPDATE feed_profiles SET last_pulled_at = :at WHERE id = :feed AND tenant_id = :tenant',
            [
                'at' => $this->utc($at)->format(self::SQL_FORMAT),
                'feed' => $feedId,
                'tenant' => $tenantId,
            ],
        );
    }

    public function aggregate(Tenant $tenant, ?Uuid $feedId, DateTimeImmutable $now): FeedPullAggregate
    {
        $currentBucket = $this->hourBucket($now);
        $windowStart = $currentBucket->modify('-23 hours');

        $sql = 'SELECT bucket_start, SUM(pull_count) AS hits FROM feed_pull_stats '
            .'WHERE tenant_id = :tenant AND bucket_start >= :since';
        $params = [
            'tenant' => $tenant->getId()->toRfc4122(),
            'since' => $windowStart->format(self::SQL_FORMAT),
        ];
        if (null !== $feedId) {
            $sql .= ' AND feed_id = :feed';
            $params['feed'] = $feedId->toRfc4122();
        }
        $sql .= ' GROUP BY bucket_start';

        // tenant-safe: explicit tenant_id filter (aggregate scoped to the authenticated tenant; RLS GUC is active on this session as defence in depth)
        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        $byBucket = [];
        foreach ($rows as $row) {
            $bucketRaw = $row['bucket_start'];
            $hits = $row['hits'];
            if (!\is_string($bucketRaw) || !\is_numeric($hits)) {
                continue;
            }
            $bucket = new DateTimeImmutable($bucketRaw, new DateTimeZone('UTC'));
            $byBucket[$bucket->format(self::SQL_FORMAT)] = (int) $hits;
        }

        $spark = [];
        $total = 0;
        for ($i = 0; $i < 24; ++$i) {
            $bucket = $windowStart->modify(sprintf('+%d hours', $i));
            $count = $byBucket[$bucket->format(self::SQL_FORMAT)] ?? 0;
            $total += $count;
            $spark[] = [
                'bucket' => $bucket->format(DateTimeInterface::ATOM),
                'count' => $count,
            ];
        }

        return new FeedPullAggregate($total, $spark);
    }

    private function hourBucket(DateTimeImmutable $at): DateTimeImmutable
    {
        $utc = $this->utc($at);

        return $utc->setTime((int) $utc->format('H'), 0);
    }

    private function utc(DateTimeImmutable $at): DateTimeImmutable
    {
        return $at->setTimezone(new DateTimeZone('UTC'));
    }
}
