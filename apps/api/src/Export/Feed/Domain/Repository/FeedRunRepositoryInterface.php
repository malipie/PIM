<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Repository;

use App\Export\Feed\Domain\Entity\FeedRun;
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
}
