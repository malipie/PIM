<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Repository;

use App\Export\Feed\Domain\Entity\FeedRunLog;
use Symfony\Component\Uid\Uuid;

interface FeedRunLogRepositoryInterface
{
    public function save(FeedRunLog $log): void;

    /**
     * Persist many log lines in one flush (feed-health during generation).
     *
     * @param list<FeedRunLog> $logs
     */
    public function saveMany(array $logs): void;

    /**
     * @return list<FeedRunLog>
     */
    public function findByRun(Uuid $feedRunId): array;

    /**
     * Keyset page of one run's log lines for the drill-down (XMLF-P4-03),
     * oldest first (the order they were emitted). Log ids are UUIDv7, so the
     * cursor is the last-seen log id. `$level` filters info|warning|error;
     * null = all lines.
     *
     * @return list<FeedRunLog>
     */
    public function findPageByRun(Uuid $feedRunId, ?string $level, ?Uuid $cursor, int $limit): array;
}
