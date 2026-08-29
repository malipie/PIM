<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * #3045 — a write whose audit row cannot be stored must FAIL, not look
 * successful.
 *
 * DH Auditor catches every exception its own INSERT throws, on purpose, so one
 * broken provider cannot stop the others
 * ({@see \DH\Auditor\EventSubscriber\AuditEventSubscriber}). On Postgres that
 * is not survivable: the failed INSERT aborts the transaction, the COMMIT that
 * follows is downgraded to a silent ROLLBACK, and the caller gets a success it
 * did not earn. Measured on a live instance before the fix: POST
 * /api/import-profiles answered 201 with a full resource and a Location
 * header while the table stayed empty.
 *
 * This has to be an API test, not a kernel one: the auditor's user/security
 * provider is request-scoped and writes nothing outside a real request — the
 * same reason {@see AuditWriteApiTest} lives here. A first attempt as a
 * KernelTestCase proved it by failing on "the audit row must be there on the
 * happy path": zero rows, so there was never anything to break.
 *
 * The missing table is simulated the only way that reproduces the incident:
 * by dropping it, then putting it back in `finally` so the rest of the suite
 * keeps its schema.
 */
final class AuditWriteFailureApiTest extends CatalogApiTestCase
{
    #[Test]
    #[SkipDatabaseRollback]
    public function aWriteWhoseAuditRowCannotBeStoredFailsInsteadOfLookingSuccessful(): void
    {
        $connection = $this->em()->getConnection();
        $code = 'audit_fail_'.bin2hex(random_bytes(6));

        // Rename rather than drop: the table comes back with its rows, indexes
        // and their names intact, so the rest of the suite — including the
        // schema contract — sees exactly what the migrations built.
        // tenant-safe: DDL on an audit table, no tenant-scoped rows involved.
        $connection->executeStatement('ALTER TABLE object_types_audit RENAME TO object_types_audit_hidden');

        try {
            $this->authenticatedClient()->request('POST', '/api/object_types', [
                'json' => [
                    'code' => $code,
                    'label' => ['pl' => 'Sonda', 'en' => 'Probe'],
                ],
            ]);

            // The contract is "not a success". Before #3045 this answered 201.
            self::assertResponseStatusCodeSame(Response::HTTP_INTERNAL_SERVER_ERROR);

            $persisted = $connection->fetchOne(
                'SELECT count(*) FROM object_types WHERE code = :c',
                ['c' => $code],
            );
            self::assertSame(
                0,
                (int) (\is_scalar($persisted) ? $persisted : -1),
                'the row must be gone — that is exactly what the 201 used to hide',
            );
        } finally {
            // tenant-safe: DDL, restores the table the suite expects.
            $connection->executeStatement('ALTER TABLE object_types_audit_hidden RENAME TO object_types_audit');
            $connection->executeStatement(
                'DELETE FROM object_types WHERE code = :c',
                ['c' => $code],
            );
        }
    }
}
