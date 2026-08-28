<?php

declare(strict_types=1);

namespace App\Tests\Api\Import;

use App\Catalog\Application\Reindex\BulkReindexQueueInterface;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Contracts\Service\AttributesIndexedBatchRebuilder;
use App\Catalog\Contracts\Service\BulkOperationScope;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectValueRepositoryInterface;
use App\Import\Application\Service\ImportProgressPublisher;
use App\Import\Application\Service\ImportRollbackService;
use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Repository\ImportSessionRepositoryInterface;
use App\Import\Domain\Repository\ImportUndoLogRepositoryInterface;
use App\Shared\Application\BulkOperationLock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;

/**
 * #2818 — "Operator widzi postęp i może przerwać".
 *
 * A rollback of a full catalogue runs for minutes, so stopping it has to be
 * possible without killing the worker. The request only sets a flag; the run
 * reads it between chunks and stops after the one it is on, which is what keeps
 * the stop point on a committed boundary rather than in the middle of a chunk.
 *
 * The cancelled state is deliberately NOT terminal. Some values are restored
 * and some are not; `rolled_back` would claim otherwise and `success` would
 * invite a second full replay of a half-spent undo-log. It stays
 * `rolling_back`, carries `stopped_reason`, and resumes from its checkpoint.
 */
final class ImportRollbackCancelApiTest extends CatalogApiTestCase
{
    #[Test]
    public function cancellingStopsTheRunOnAChunkBoundaryAndSaysSo(): void
    {
        $this->seedSkuName();
        $this->import("sku;name\nCAN-1;Old1\nCAN-2;Old2\n");
        $sessionId = $this->import("sku;name\nCAN-1;New1\nCAN-2;New2\nCAN-3;New3\n");

        self::assertSame(3, $this->countObjects('CAN-%'), 'precondition: CAN-3 was created by import #2');

        $session = $this->reloadSession($sessionId);
        $session->markRollbackStarted(new DateTimeImmutable());
        self::getContainer()->get(ImportSessionRepositoryInterface::class)->save($session);

        // The operator presses "Przerwij" while the run is between chunks. The
        // decorator stands in for that timing: the flag lands on the session the
        // run re-reads after its first committed chunk.
        $service = $this->rollbackServiceWith(new CancelAfterFirstReadRepository(
            self::getContainer()->get(ImportSessionRepositoryInterface::class),
        ));
        $outcome = $service->run($this->reloadSession($sessionId));

        self::assertFalse($outcome['completed'], 'the run reports that it did not finish');
        self::assertSame('cancelled', $outcome['stoppedReason']);

        $this->em()->clear();
        $afterCancel = $this->reloadSession($sessionId);
        self::assertSame('rolling_back', $afterCancel->getStatus()->value, 'a cancelled rollback is not a finished one');
        $report = $afterCancel->getRollbackReport() ?? [];
        self::assertSame('stopped', $report['phase'] ?? null);
        self::assertSame('cancelled', $report['stopped_reason'] ?? null);

        // The values phase committed before the stop, so its work stands...
        self::assertSame('Old1', $this->nameOf('CAN-1'), 'the committed part of the undo stands');
        // ...and the part that had not run yet is untouched, not half-done.
        self::assertSame(3, $this->countObjects('CAN-%'), 'the created object is still there — that phase never ran');

        // Resuming picks the same job back up and finishes it.
        $client = $this->authenticatedClient();
        $client->request('POST', \sprintf('/api/import-sessions/%s/rollback/resume', $sessionId));
        self::assertResponseIsSuccessful();
        $body = $this->decode($client);

        self::assertSame('rolled_back', $body['status']);
        self::assertSame(1, $body['deleted_objects'], 'CAN-3 deleted by the resumed run');
        self::assertSame(2, $this->countObjects('CAN-%'));
    }

    /**
     * #2818 — cancelling something that is not rolling back is a conflict, not
     * a silent no-op.
     */
    #[Test]
    public function cancellingASessionThatIsNotRollingBackIsRefused(): void
    {
        $this->seedSkuName();
        $sessionId = $this->import("sku;name\nNOP-1;One\n");

        $client = $this->authenticatedClient();
        $client->request('POST', \sprintf('/api/import-sessions/%s/rollback/cancel', $sessionId));
        self::assertResponseStatusCodeSame(409);
    }

    private function rollbackServiceWith(ImportSessionRepositoryInterface $sessions): ImportRollbackService
    {
        $c = self::getContainer();

        return new ImportRollbackService(
            $this->em(),
            $c->get(Connection::class),
            $sessions,
            $c->get(ImportUndoLogRepositoryInterface::class),
            $c->get(ObjectValueRepositoryInterface::class),
            $c->get(AttributesIndexedBatchRebuilder::class),
            $c->get(BulkOperationScope::class),
            $c->get(BulkReindexQueueInterface::class),
            $c->get(BulkOperationLock::class),
            $c->get(TenantContext::class),
            $c->get(ImportProgressPublisher::class),
        );
    }

    private function reloadSession(string $sessionId): ImportSession
    {
        $session = self::getContainer()->get(ImportSessionRepositoryInterface::class)
            ->findById(Uuid::fromString($sessionId));
        \assert($session instanceof ImportSession);

        return $session;
    }

    private function import(string $csv): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pim-can-').'.csv';
        file_put_contents($path, $csv);

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'name' => 'name'], JSON_THROW_ON_ERROR),
                        'mode' => 'UPSERT',
                    ],
                    'files' => ['file' => new UploadedFile($path, 'can.csv', 'text/csv', null, true)],
                ],
            ]);
            self::assertResponseIsSuccessful();

            $id = $this->decode($client)['id'] ?? null;

            return \is_scalar($id) ? (string) $id : '';
        } finally {
            @unlink($path);
        }
    }

    private function nameOf(string $code): ?string
    {
        $value = $this->em()->getConnection()->fetchOne(
            <<<'SQL'
                SELECT ov.value->>'value'
                FROM object_values ov
                JOIN objects o ON o.id = ov.object_id
                JOIN attributes a ON a.id = ov.attribute_id
                WHERE o.code = :code AND a.code = 'name'
                LIMIT 1
                SQL,
            ['code' => $code],
        );

        return \is_scalar($value) && false !== $value ? (string) $value : null;
    }

    private function countObjects(string $like): int
    {
        $count = $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM objects WHERE code LIKE :like',
            ['like' => $like],
        );

        return (int) (\is_scalar($count) ? $count : 0);
    }

    /**
     * @return array<mixed>
     */
    private function decode(\ApiPlatform\Symfony\Bundle\Test\Client $client): array
    {
        $decoded = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function seedSkuName(): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $product = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($product instanceof ObjectType);

        $sku = new Attribute('sku', ['en' => 'SKU'], AttributeType::Text);
        $name = new Attribute('name', ['en' => 'Name'], AttributeType::Text);
        $em->persist($sku);
        $em->persist($name);
        $em->persist(new ObjectTypeAttribute($product, $sku, false, 1));
        $em->persist(new ObjectTypeAttribute($product, $name, false, 2));
        $em->flush();
    }
}

/**
 * Stands in for the operator pressing "Przerwij" while the first chunk is being
 * replayed: every session the run re-reads between chunks carries the cancel
 * request, exactly as it would once the endpoint had written it from the
 * request process.
 */
final class CancelAfterFirstReadRepository implements ImportSessionRepositoryInterface
{
    public function __construct(private readonly ImportSessionRepositoryInterface $inner)
    {
    }

    public function save(ImportSession $session): void
    {
        $this->inner->save($session);
    }

    public function findById(Uuid $id): ?ImportSession
    {
        $session = $this->inner->findById($id);
        if ($session instanceof ImportSession && $session->getStatus()->isRollingBack()) {
            $session->requestRollbackCancel();
        }

        return $session;
    }

    /**
     * @return list<ImportSession>
     */
    public function findByTenantAndUser(Tenant $tenant, Uuid $userId, int $limit = 50): array
    {
        return $this->inner->findByTenantAndUser($tenant, $userId, $limit);
    }
}
