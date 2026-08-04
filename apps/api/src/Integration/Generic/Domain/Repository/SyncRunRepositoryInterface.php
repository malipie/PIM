<?php

declare(strict_types=1);

namespace App\Integration\Generic\Domain\Repository;

use App\Integration\Generic\Domain\Entity\SyncBinding;
use App\Integration\Generic\Domain\Entity\SyncRun;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

interface SyncRunRepositoryInterface
{
    public function save(SyncRun $run): void;

    public function findById(Uuid $id): ?SyncRun;

    /**
     * @return list<SyncRun>
     */
    public function findByBinding(SyncBinding $binding): array;

    /**
     * Latest still-running run of the binding started after $since — the
     * redelivery guard: a queued/redelivered message must not start a second
     * concurrent run of the same binding (#2722). The $since floor keeps a run
     * orphaned by a hard worker kill (never finalized) from blocking the
     * binding forever.
     */
    public function findRunningByBinding(SyncBinding $binding, DateTimeImmutable $since): ?SyncRun;
}
