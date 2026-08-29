<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit;

use DH\Auditor\Event\LifecycleEvent;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * #3045 — turns a swallowed audit write into a loud failure.
 *
 * DH Auditor persists its row from an event listener and catches every
 * exception that write throws, so one broken provider cannot stop the others
 * ({@see \DH\Auditor\EventSubscriber\AuditEventSubscriber}). On Postgres the
 * miss is not survivable: the failed INSERT puts the transaction in the
 * aborted state, every later statement is refused, and the COMMIT that follows
 * is downgraded to a silent ROLLBACK. The request still answers 201/202 and
 * the write is gone. Measured on a live instance: POST /api/import-profiles
 * returned 201 with a Location header while the table stayed empty.
 *
 * This listener runs AFTER the auditor's (priority -1_000_000, "should be
 * fired last") and asks the connection one cheap question: are you still
 * usable? A doomed transaction answers with an error, which becomes
 * {@see AuditWriteFailedException} naming the table — instead of a 201 that
 * lies.
 *
 * Cost is one probe per FLUSH, not per audited row: a bulk path writes
 * thousands of audit rows inside one flush and must not pay a round-trip
 * each. postFlush arms the next probe. Keying the flag on the transaction
 * nesting level instead would miss two back-to-back transactions opened at
 * the same level — the second would never be probed.
 */
#[AsEventListener(event: LifecycleEvent::class, method: 'onAuditEvent', priority: -2_000_000)]
#[AsDoctrineListener(event: Events::postFlush)]
final class AuditWriteGuard
{
    private bool $probedThisFlush = false;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function onAuditEvent(LifecycleEvent $event): void
    {
        // Outside a transaction a failed audit INSERT poisons nothing — there
        // is no pending work that a silent rollback could take with it.
        if ($this->probedThisFlush || $this->connection->getTransactionNestingLevel() < 1) {
            return;
        }
        $this->probedThisFlush = true;

        try {
            // tenant-safe: liveness probe, touches no table and returns no row
            $this->connection->executeQuery('SELECT 1');
        } catch (DbalException $failure) {
            $payload = $event->getPayload();
            $table = \is_string($payload['table'] ?? null) ? $payload['table'] : '(unknown)';

            throw new AuditWriteFailedException($table, $failure);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $this->probedThisFlush = false;
    }
}
