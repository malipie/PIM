<?php

declare(strict_types=1);

namespace App\Tests\Api\Import;

use App\Catalog\Application\Reindex\BulkReindexQueueInterface;
use App\Catalog\Contracts\Service\AttributesIndexedBatchRebuilder;
use App\Catalog\Contracts\Service\BulkOperationScope;
use App\Catalog\Domain\AttributeType;
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
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;

/**
 * #2818 — what an interrupted rollback must leave behind.
 *
 * The pre-#2818 contract was all-or-nothing: one transaction, so a crash undid
 * every mutation and the session stayed `success`. That guarantee cost the
 * operation itself — a full catalogue could not be undone at all, because the
 * work outlives any request and a transaction that long holds every touched row
 * for its whole duration (measured: over ten minutes for 13 895 objects, killed
 * by the connection closing). Undoing in chunks trades atomicity for actually
 * finishing, and the ticket names the price: part of the catalogue is restored
 * and part is not.
 *
 * What must hold instead — and what this pins:
 *   - the intermediate state is VISIBLE. The session reads `rolling_back`,
 *     never `success` (which would invite a second run over a half-spent
 *     undo-log) and never `rolled_back` (which would claim work not done);
 *   - work already committed STAYS committed, so a resume is not a restart;
 *   - resuming finishes the job exactly once — no value restored twice, no
 *     object deleted twice.
 */
final class ImportRollbackAtomicityApiTest extends CatalogApiTestCase
{
    #[Test]
    public function interruptedRollbackStaysVisiblyIncompleteAndResumesCleanly(): void
    {
        $this->seedSkuName();

        // Import #1 seeds ATM-1..2 with the "old" names.
        $this->import("sku;name\nATM-1;Old1\nATM-2;Old2\n");
        // Import #2 overwrites those names and creates ATM-3..4.
        $sessionId = $this->import("sku;name\nATM-1;New1\nATM-2;New2\nATM-3;New3\nATM-4;New4\n");

        self::assertSame('New1', $this->nameOf('ATM-1'), 'precondition: import #2 overwrote the name');
        self::assertSame(4, $this->countObjects('ATM-%'), 'precondition: 4 objects exist (2 pre-existing + 2 created)');

        $session = $this->reloadSession($sessionId);
        $session->markRollbackStarted(new DateTimeImmutable());
        self::getContainer()->get(ImportSessionRepositoryInterface::class)->save($session);

        // Interrupt the run at its first progress write — after the first chunk
        // of the undo-log has been replayed and committed. This is the window
        // that used to be impossible to observe: a worker killed by an OOM, a
        // deploy or a FrankenPHP restart mid-rollback.
        $service = $this->rollbackServiceWith(new ThrowOnProgressSaveRepository(
            self::getContainer()->get(ImportSessionRepositoryInterface::class),
        ));

        $threw = false;
        try {
            $service->run($this->reloadSession($sessionId));
        } catch (RuntimeException $e) {
            $threw = true;
            self::assertSame('boom: worker died mid-rollback', $e->getMessage());
        }
        self::assertTrue($threw, 'precondition: the injected crash actually fired');

        // ── The interrupted state is honest ─────────────────────────────────
        $this->em()->clear();
        $afterCrash = $this->reloadSession($sessionId);
        self::assertSame(
            'rolling_back',
            $afterCrash->getStatus()->value,
            'an interrupted rollback must keep saying it is rolling back — not `success`, which would invite a '
            .'second run over a half-spent undo-log, and not `rolled_back`, which would claim work it did not do',
        );
        self::assertFalse($afterCrash->getStatus()->isTerminal(), 'the session is not finished');

        // Work that committed before the interruption is still committed: the
        // first chunk's values are restored. (Both objects fit one chunk here,
        // so the whole replay landed before the progress write blew up.)
        self::assertSame('Old1', $this->nameOf('ATM-1'), 'committed restore survives the crash');

        // ── Resuming finishes the job, exactly once ─────────────────────────
        // A separate verb: POSTing /rollback again is refused precisely because
        // the session is still `rolling_back`.
        $client = $this->authenticatedClient();
        $client->request('POST', \sprintf('/api/import-sessions/%s/rollback', $sessionId));
        self::assertResponseStatusCodeSame(409, 'starting a second rollback over a stopped one must be refused');

        $client->request('POST', \sprintf('/api/import-sessions/%s/rollback/resume', $sessionId));
        self::assertResponseIsSuccessful();
        $body = $this->decode($client);

        self::assertSame('rolled_back', $body['status']);
        self::assertSame(2, $body['deleted_objects'], 'ATM-3 + ATM-4 deleted once');
        self::assertSame(4, $body['restored_values'], 'ATM-1/ATM-2 sku+name restored once across both runs');
        self::assertSame(0, $body['skipped_manual_edits']);

        $this->em()->clear();
        self::assertSame('Old1', $this->nameOf('ATM-1'), 'the pre-import value is restored');
        self::assertSame('Old2', $this->nameOf('ATM-2'));
        self::assertSame(2, $this->countObjects('ATM-%'), 'the created objects are gone');
    }

    /**
     * #2818 — a rollback in flight refuses a second one. Two runs replaying the
     * same undo-log would race over the same rows.
     */
    #[Test]
    public function aSecondRollbackRequestIsRefusedWhileOneIsRunning(): void
    {
        $this->seedSkuName();
        $this->import("sku;name\nCNF-1;Old1\n");
        $sessionId = $this->import("sku;name\nCNF-1;New1\n");

        $session = $this->reloadSession($sessionId);
        $session->markRollbackStarted(new DateTimeImmutable());
        self::getContainer()->get(ImportSessionRepositoryInterface::class)->save($session);

        $client = $this->authenticatedClient();
        $client->request('GET', \sprintf('/api/import-sessions/%s/rollback-preview', $sessionId));
        self::assertResponseIsSuccessful();
        self::assertFalse(
            $this->decode($client)['rollbackable'],
            'the preview must not offer a rollback of a session already rolling back',
        );

        $client->request('POST', \sprintf('/api/import-sessions/%s/rollback', $sessionId));
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
        $path = tempnam(sys_get_temp_dir(), 'pim-atm-').'.csv';
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
                    'files' => ['file' => new UploadedFile($path, 'atm.csv', 'text/csv', null, true)],
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
 * Session-repository decorator that reads normally but dies on the first
 * progress write — the save that follows a committed chunk. Models a worker
 * killed mid-rollback (OOM, deploy, FrankenPHP restart) with some of the
 * catalogue already restored.
 */
final class ThrowOnProgressSaveRepository implements ImportSessionRepositoryInterface
{
    public function __construct(private readonly ImportSessionRepositoryInterface $inner)
    {
    }

    public function save(ImportSession $session): void
    {
        throw new RuntimeException('boom: worker died mid-rollback');
    }

    public function findById(Uuid $id): ?ImportSession
    {
        return $this->inner->findById($id);
    }

    /**
     * @return list<ImportSession>
     */
    public function findByTenantAndUser(Tenant $tenant, Uuid $userId, int $limit = 50): array
    {
        return $this->inner->findByTenantAndUser($tenant, $userId, $limit);
    }
}
