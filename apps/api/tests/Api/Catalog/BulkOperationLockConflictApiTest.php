<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Domain\Entity\BulkSession;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Catalog\Presentation\Controller\BulkEditController;
use App\Shared\Application\BulkOperationLock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;

/**
 * IMP2-2.9 (#1485, ADR-0019 D11) — every catalog bulk-write entry point shares
 * the per-tenant {@see BulkOperationLock} with imports. When the lock is held
 * (an import or another bulk op is running), a second bulk request must fail
 * fast with HTTP 409, not queue or run concurrently. The matrix is documented
 * in `docs/architecture/concurrency-matrix.md`.
 */
final class BulkOperationLockConflictApiTest extends CatalogApiTestCase
{
    #[Test]
    public function bulkEditReturns409WhenLockHeld(): void
    {
        $client = $this->authenticatedClient();
        $lock = $this->holdBulkLock();

        try {
            $client->request('POST', '/api/products/bulk-edit', [
                'headers' => ['content-type' => 'application/json'],
                'body' => json_encode([
                    'operation' => 'toggle_enabled',
                    'product_ids' => [Uuid::v7()->toRfc4122()],
                    'payload' => ['enabled' => true],
                ], JSON_THROW_ON_ERROR),
            ]);

            self::assertResponseStatusCodeSame(409);
        } finally {
            $lock->release();
        }
    }

    #[Test]
    public function bulkActionsApplyReturns409WhenLockHeld(): void
    {
        $client = $this->authenticatedClient();
        $lock = $this->holdBulkLock();

        try {
            $client->request('POST', '/api/products/bulk-actions/set_attribute', [
                'headers' => ['content-type' => 'application/json'],
                'body' => json_encode([
                    'target_ids' => [Uuid::v7()->toRfc4122()],
                    'payload' => ['attribute_code' => 'name', 'value' => 'x'],
                ], JSON_THROW_ON_ERROR),
            ]);

            self::assertResponseStatusCodeSame(409);
        } finally {
            $lock->release();
        }
    }

    #[Test]
    public function bulkSessionRollbackReturns409WhenLockHeld(): void
    {
        $sessionId = $this->seedBulkSession();
        $client = $this->authenticatedClient();
        $lock = $this->holdBulkLock();

        try {
            $client->request('POST', \sprintf('/api/bulk-sessions/%s/rollback', $sessionId), [
                'headers' => ['content-type' => 'application/json'],
                'body' => '{}',
            ]);

            self::assertResponseStatusCodeSame(409);
        } finally {
            $lock->release();
        }
    }

    /**
     * IMP2-2.9 (#1485) — once the contending operation frees the lock, the next
     * bulk-edit runs normally: the 409 is a transient back-off, not a permanent
     * block. Proves the per-tenant lock is non-blocking AND fully released.
     */
    #[Test]
    public function bulkEditSucceedsAfterLockReleased(): void
    {
        $product = $this->seedProduct('SKU-LOCK-RELEASED');

        // The contending op holds and then frees the lock before the request.
        $this->holdBulkLock()->release();

        $client = $this->authenticatedClient();
        $response = $client->request('POST', '/api/products/bulk-edit', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode([
                'operation' => 'toggle_enabled',
                'product_ids' => [$product->getId()->toRfc4122()],
                'payload' => ['enabled' => false],
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(202);
        $payload = $response->toArray();
        self::assertSame('completed', $payload['status']);
        self::assertSame(1, $payload['total']);
        self::assertSame(1, $payload['processed']);
        self::assertSame(0, $payload['errors_count']);
    }

    /**
     * IMP2-2.9 (#1485) — the controller acquires the lock then runs the batch in
     * a `try { … } finally { $lock->release() }`. An exception raised AFTER the
     * acquire (here the post-persist `flush()` blows up) must still release the
     * lock, or the tenant would be wedged at 409 until the 1h TTL. Driven by a
     * unit-style invocation with a real lock + a flush-throwing EntityManager so
     * the failure point is deterministic (the API loop swallows per-row errors,
     * so a payload alone cannot exercise this seam).
     */
    #[Test]
    public function bulkEditReleasesLockOnException(): void
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($tenant);

        $bulkLock = self::getContainer()->get(BulkOperationLock::class);
        $objects = self::getContainer()->get(CatalogObjectRepositoryInterface::class);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(new RuntimeException('flush exploded mid-batch'));

        $statePolicy = self::getContainer()->get(\App\Identity\Contracts\Policy\WorkflowStateEditPolicyInterface::class);
        $controller = new BulkEditController($objects, $statePolicy, $tenantContext, $em, $bulkLock);

        $request = new Request(
            content: json_encode([
                'operation' => 'toggle_enabled',
                'product_ids' => [Uuid::v7()->toRfc4122()],
                'payload' => ['enabled' => true],
            ], JSON_THROW_ON_ERROR),
        );

        try {
            $controller->bulkEdit($request);
            self::fail('the flush-throwing EntityManager must propagate the exception');
        } catch (RuntimeException $e) {
            self::assertSame('flush exploded mid-batch', $e->getMessage());
        }

        // The lock is freed in the `finally` despite the exception: the next
        // acquire on the same tenant succeeds instead of returning null.
        $retry = $bulkLock->acquire($tenant);
        self::assertNotNull($retry, 'the lock must be released in finally after the exception');
        $retry->release();
    }

    private function seedProduct(string $code): CatalogObject
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($tenant);

        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $type);

        $object = new CatalogObject($type, $code);
        $object->changeEnabled(true);
        $em = $this->em();
        $em->persist($object);
        $em->flush();

        $tenantContext->clear();

        return $object;
    }

    private function holdBulkLock(): LockInterface
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $lock = self::getContainer()->get(BulkOperationLock::class)->acquire($tenant);
        self::assertNotNull($lock, 'precondition: the test holds the bulk lock');

        return $lock;
    }

    private function seedBulkSession(): string
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $session = new BulkSession(
            actionType: 'set_attribute',
            targetObjectIds: [Uuid::v7()->toRfc4122()],
            actionPayload: ['attribute_code' => 'name', 'value' => 'x'],
        );
        $session->assignTenant($tenant);
        $em->persist($session);
        $em->flush();

        return $session->getId()->toRfc4122();
    }
}
