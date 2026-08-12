<?php

declare(strict_types=1);

namespace App\Import\Domain\Entity\Concerns;

use App\Import\Domain\Enum\ImportSessionStatus;
use DateTimeImmutable;
use LogicException;

/**
 * #2818 — the state of a rollback RUN, split off {@see \App\Import\Domain\Entity\ImportSession}.
 *
 * Undoing an import stopped being an instant between two page loads when it
 * moved to the worker: it is now a job with a lifecycle of its own — queued,
 * working through phases, cancellable, interruptible, resumable — and that
 * lifecycle is what lives here. The session keeps its own (its rows, its
 * counters, its window); this keeps the undo's.
 *
 * The progress lives in the same `rollback_report` JSONB column the finished
 * rollback publishes, so the UI reads one place from the first chunk to the
 * last. Whoever writes it must carry the cancel flag across: the column is
 * rebuilt whole on every write, and dropping the flag would make the operator's
 * "Przerwij" look like it did nothing.
 *
 * @phpstan-require-extends \App\Import\Domain\Entity\ImportSession
 */
trait RollbackRunState
{
    /**
     * #2818 — hand the session to the rollback worker.
     *
     * The rollback used to run inside the HTTP request that asked for it,
     * which put a full-catalogue undo (13 895 objects, over ten minutes of
     * work) out of reach of any 30-second request budget. It is queued now,
     * and the session says so: `rolling_back` is a state the operator can see,
     * and a status that no longer answers {@see ImportSessionStatus::isRollbackable()}
     * so a second request is refused instead of replaying an undo-log that a
     * running worker is already replaying.
     *
     * @param DateTimeImmutable $now the instant the rollback was authorized —
     *                               the same value the worker later passes to {@see markRolledBack()},
     *                               so a window lapsing mid-run cannot strand a session that
     *                               already had its data undone
     */
    public function markRollbackStarted(DateTimeImmutable $now): void
    {
        if (!$this->getStatus()->isRollbackable()) {
            throw new LogicException(\sprintf(
                'Import session %s cannot be rolled back from status "%s".',
                $this->id->toRfc4122(),
                $this->status,
            ));
        }
        if (null === $this->rollbackUntil || $this->rollbackUntil < $now) {
            throw new LogicException(\sprintf(
                'Rollback window for import session %s has expired.',
                $this->id->toRfc4122(),
            ));
        }
        $this->status = ImportSessionStatus::RollingBack->value;
        $this->rollbackReport = [
            'phase' => 'queued',
            'objects_done' => 0,
            'objects_total' => null,
            'cancel_requested' => false,
        ];
        $this->progressUpdatedAt = new DateTimeImmutable();
    }

    /**
     * #2818 — how far the rollback has got, written per chunk.
     *
     * Lives in the same report the finished rollback publishes, so the UI reads
     * one place throughout, and next to {@see $progressUpdatedAt}, which is
     * what separates a slow undo from a stalled one.
     */
    public function recordRollbackProgress(int $objectsDone, int $objectsTotal, string $phase): void
    {
        $report = $this->rollbackReport ?? [];
        $report['phase'] = $phase;
        $report['objects_done'] = $objectsDone;
        $report['objects_total'] = $objectsTotal;
        $this->rollbackReport = $report;
        $this->progressUpdatedAt = new DateTimeImmutable();
    }

    /**
     * #2818 — ask a running rollback to stop after the chunk it is on.
     *
     * Persisted rather than signalled in memory: the worker runs in another
     * process, and re-reads the session between chunks.
     */
    public function requestRollbackCancel(): void
    {
        if (!$this->getStatus()->isRollingBack()) {
            throw new LogicException(\sprintf(
                'Import session %s is not rolling back (status "%s").',
                $this->id->toRfc4122(),
                $this->status,
            ));
        }
        $report = $this->rollbackReport ?? [];
        $report['cancel_requested'] = true;
        $this->rollbackReport = $report;
    }

    /**
     * #2818 — pick a stopped rollback back up.
     *
     * Resuming is an explicit act, not a re-click of "Wycofaj": the session is
     * already `rolling_back`, so the entry guard on {@see markRollbackStarted()}
     * refuses it, and it must — that guard is what stops two workers replaying
     * one undo-log. This clears the cancel flag and leaves the checkpoint alone,
     * so the queued run continues from where the last one stopped. If a worker
     * IS somehow still alive, the per-tenant bulk lock makes the new run wait
     * and then continue from a checkpoint the first one has moved on — safe,
     * because every step of the replay is idempotent.
     */
    public function resumeRollback(): void
    {
        if (!$this->getStatus()->isRollingBack()) {
            throw new LogicException(\sprintf(
                'Import session %s is not rolling back (status "%s").',
                $this->id->toRfc4122(),
                $this->status,
            ));
        }
        $report = $this->rollbackReport ?? [];
        $report['cancel_requested'] = false;
        unset($report['stopped_reason']);
        $report['phase'] = 'queued';
        $this->rollbackReport = $report;
        $this->progressUpdatedAt = new DateTimeImmutable();
    }

    public function isRollbackCancelRequested(): bool
    {
        return true === ($this->rollbackReport['cancel_requested'] ?? false);
    }

    /**
     * #2818 — a rollback that stopped before finishing: cancelled by the
     * operator, or interrupted.
     *
     * The session STAYS `rolling_back` on purpose. Some values are restored and
     * some are not, and the one thing that must never happen is that state
     * being invisible: `rolled_back` would claim work that was not done, and
     * `success` would invite a second run over an undo-log already half
     * replayed. `rolling_back` plus a checkpoint is resumable and honest.
     */
    public function recordRollbackStopped(int $objectsDone, int $objectsTotal, string $reason): void
    {
        $report = $this->rollbackReport ?? [];
        $report['phase'] = 'stopped';
        $report['objects_done'] = $objectsDone;
        $report['objects_total'] = $objectsTotal;
        $report['stopped_reason'] = $reason;
        $this->rollbackReport = $report;
        $this->progressUpdatedAt = new DateTimeImmutable();
    }
}
