<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/** AUD-DATA-003 (#3019) — real HTTP write reaches the DH Auditor table. */
final class AuditWriteApiTest extends CatalogApiTestCase
{
    #[Test]
    #[SkipDatabaseRollback]
    public function insertingAnAuditedEntityWritesItsAuditRow(): void
    {
        $code = 'audit_probe_'.bin2hex(random_bytes(6));
        $id = null;
        $connection = $this->em()->getConnection();

        try {
            $response = $this->authenticatedClient()->request('POST', '/api/object_types', [
                'json' => [
                    'code' => $code,
                    'label' => ['pl' => 'Audit probe', 'en' => 'Audit probe'],
                ],
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

            $body = $response->toArray();
            self::assertIsString($body['id'] ?? null);
            $id = $body['id'];

            $auditTypes = $connection->fetchFirstColumn(
                'SELECT type FROM object_types_audit WHERE object_id = :id ORDER BY id',
                ['id' => $id],
            );

            self::assertContains('insert', $auditTypes);
        } finally {
            if (\is_string($id)) {
                // tenant-safe: the random probe belongs to this test tenant;
                // raw cleanup avoids emitting a second audit event.
                $connection->executeStatement(
                    'DELETE FROM object_types_audit WHERE object_id = :id',
                    ['id' => $id],
                );
                $connection->executeStatement(
                    'DELETE FROM object_types WHERE id = :id',
                    ['id' => $id],
                );
            }
        }
    }
}
