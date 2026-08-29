<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit;

use RuntimeException;
use Throwable;

/**
 * #3045 — the audit row could not be written and the surrounding transaction
 * is therefore doomed.
 *
 * DH Auditor swallows every exception from its own write on purpose
 * ({@see \DH\Auditor\EventSubscriber\AuditEventSubscriber::onAuditEvent}:
 * `catch (\Exception) { // do nothing to ensure other providers are called }`).
 * On Postgres that is not a survivable miss: the failed INSERT aborts the
 * transaction, the following COMMIT is silently downgraded to ROLLBACK, and
 * the request returns 201/202 having lost the write. Measured on a live
 * instance: `POST /api/import-profiles` answered 201 with a full resource and
 * a Location header while `SELECT count(*) FROM import_profiles` stayed 0.
 *
 * Raising this turns that into a loud 5xx that names the table, which is the
 * whole point: a write that did not happen must never look like one that did.
 */
final class AuditWriteFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $auditTable,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf(
                'Audit write to "%s" failed and left the transaction unusable — the surrounding write was NOT persisted. '
                .'Most often the audit table is missing for an entity listed in dh_auditor.yaml; '
                .'run `php bin/console pim:db:schema:validate` to confirm.',
                $auditTable,
            ),
            0,
            $previous,
        );
    }
}
