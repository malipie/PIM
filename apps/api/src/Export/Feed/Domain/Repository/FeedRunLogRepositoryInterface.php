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
}
