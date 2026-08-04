<?php

declare(strict_types=1);

namespace App\Integration\Generic\Application\Sync;

use App\Integration\Generic\Domain\Entity\SyncRun;
use App\Integration\Generic\Domain\Enum\SyncRunStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Finalizes a {@see SyncRun} left `running` by a mid-run throw (#2722).
 *
 * The throw may have CLOSED the EntityManager (anything from flush()), so the
 * registry resets it first and the terminal write lands on a fresh, open one —
 * the same recovery the import engine uses (IMP2-1.9). Best-effort by design:
 * when even the reset manager cannot write (connection-level fault), the run
 * stays `running` and the repository's time-floored redelivery guard is the
 * backstop.
 */
final class SyncRunFinalizer
{
    public static function markFailedAfterException(ManagerRegistry $registry, Uuid $runId): void
    {
        try {
            $registry->resetManager();
        } catch (Throwable) {
            // Best effort — still try to fetch a usable manager below.
        }

        try {
            $em = $registry->getManager();
            if (!$em instanceof EntityManagerInterface) {
                return;
            }

            $run = $em->find(SyncRun::class, $runId->toRfc4122());
            if (!$run instanceof SyncRun || SyncRunStatus::Running !== $run->getStatus()) {
                return;
            }

            $run->markFinished(SyncRunStatus::Failed);
            $em->flush();
        } catch (Throwable) {
            // Connection-level fault — the original exception (re-thrown by the
            // caller) carries the diagnosis; losing this write must not mask it.
        }
    }
}
