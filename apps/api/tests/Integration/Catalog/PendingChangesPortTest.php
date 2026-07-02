<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Contracts\PendingChanges\PendingChangeDraft;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeStatus;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use ArrayIterator;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Iterator;
use IteratorIterator;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AGENT-P0-03 (#1946) — pending_changes approval-gate hook (ADR-0024 c).
 *
 * Covers the port surface end-to-end against real Postgres: materialize
 * (chunked), list/iterate/count, pending->accepted/rejected/expired
 * transitions with idempotency, the value-envelope canon guard, and the
 * DoD tenant-isolation smoke (cross-read = 0).
 */
final class PendingChangesPortTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function materializeListAcceptRoundTrip(): void
    {
        $tenant = $this->createTenant('alpha');
        $this->activateTenantFilter($tenant);

        $port = $this->port();
        $batchId = Uuid::v7();
        $objectId = Uuid::v7();

        $count = $port->materialize($batchId, 'agent', [
            new PendingChangeDraft(
                changeType: PendingChangeType::Value,
                targetObjectId: $objectId,
                attributeCode: 'price',
                before: null,
                after: ['value' => 100],
                meta: ['run_id' => Uuid::v7()->toRfc4122(), 'model' => 'claude-sonnet-test'],
            ),
            new PendingChangeDraft(
                changeType: PendingChangeType::Value,
                targetObjectId: Uuid::v7(),
                attributeCode: 'price',
                scopeLocale: 'pl',
                before: ['value' => 90],
                after: ['value' => 100],
            ),
        ]);

        self::assertSame(2, $count);
        self::assertSame(2, $port->countBatch($batchId));

        $rows = $port->listBatch($batchId);
        self::assertCount(2, $rows);
        self::assertSame('agent', $rows[0]->provenance);
        self::assertSame(PendingChangeStatus::Pending, $rows[0]->status);
        self::assertSame(['value' => 100], $rows[0]->after);
        self::assertNull($rows[0]->before);
        self::assertTrue($objectId->equals($rows[0]->targetObjectId ?? Uuid::v4()));
        self::assertSame('pl', $rows[1]->scopeLocale);

        $streamed = iterator_count($this->toIterator($port->iterateBatch($batchId)));
        self::assertSame(2, $streamed);

        // Accept transitions all pending rows exactly once (idempotent).
        self::assertSame(2, $port->accept($batchId));
        self::assertSame(0, $port->accept($batchId), 'second accept must transition 0 rows');

        $accepted = $port->listBatch($batchId);
        self::assertSame(PendingChangeStatus::Accepted, $accepted[0]->status);
        self::assertNotNull($accepted[0]->decidedAt);
    }

    #[Test]
    public function rejectAndExpireOnlyTouchPendingRows(): void
    {
        $tenant = $this->createTenant('alpha');
        $this->activateTenantFilter($tenant);

        $port = $this->port();

        $rejectBatch = Uuid::v7();
        $port->materialize($rejectBatch, 'agent', [
            new PendingChangeDraft(changeType: PendingChangeType::Schema, after: ['attribute' => ['code' => 'weight']]),
        ]);
        self::assertSame(1, $port->reject($rejectBatch));
        self::assertSame(PendingChangeStatus::Rejected, $port->listBatch($rejectBatch)[0]->status);
        self::assertSame(0, $port->expire($rejectBatch), 'expire must not touch already-rejected rows');

        $expireBatch = Uuid::v7();
        $port->materialize($expireBatch, 'agent', [
            new PendingChangeDraft(changeType: PendingChangeType::Category, targetObjectId: Uuid::v7()),
        ]);
        self::assertSame(1, $port->expire($expireBatch));
        self::assertSame(PendingChangeStatus::Expired, $port->listBatch($expireBatch)[0]->status);
    }

    #[Test]
    public function valueDraftWithoutEnvelopeIsRejected(): void
    {
        $tenant = $this->createTenant('alpha');
        $this->activateTenantFilter($tenant);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/value envelope/');

        $this->port()->materialize(Uuid::v7(), 'agent', [
            new PendingChangeDraft(
                changeType: PendingChangeType::Value,
                targetObjectId: Uuid::v7(),
                attributeCode: 'price',
                after: ['raw' => 100],
            ),
        ]);
    }

    #[Test]
    public function pendingChangesAreIsolatedByTenantFilter(): void
    {
        $alpha = $this->createTenant('alpha');
        $beta = $this->createTenant('beta');

        $port = $this->port();

        $this->activateTenantFilter($alpha);
        $alphaBatch = Uuid::v7();
        $port->materialize($alphaBatch, 'agent', [
            new PendingChangeDraft(
                changeType: PendingChangeType::Value,
                targetObjectId: Uuid::v7(),
                attributeCode: 'price',
                after: ['value' => 'alpha-only'],
            ),
        ]);
        self::assertSame(1, $port->countBatch($alphaBatch));

        // Beta context: alpha's batch is invisible (cross-read = 0) and
        // cannot be transitioned (cross-write = 0 affected rows).
        $this->activateTenantFilter($beta);
        self::assertSame(0, $port->countBatch($alphaBatch), 'TenantFilter must hide alpha batch from beta context');
        self::assertCount(0, $port->listBatch($alphaBatch));
        self::assertSame(0, $port->accept($alphaBatch), 'beta context must not accept alpha rows');

        $this->activateTenantFilter($alpha);
        $alphaRows = $port->listBatch($alphaBatch);
        self::assertCount(1, $alphaRows);
        self::assertSame(PendingChangeStatus::Pending, $alphaRows[0]->status, 'alpha rows stay pending after beta cross-accept attempt');
    }

    #[Test]
    public function materializeIsChunkSafeForLargeBatches(): void
    {
        $tenant = $this->createTenant('alpha');
        $this->activateTenantFilter($tenant);

        $port = $this->port();
        $batchId = Uuid::v7();

        $drafts = (static function (): iterable {
            for ($i = 0; $i < 450; ++$i) {
                yield new PendingChangeDraft(
                    changeType: PendingChangeType::Value,
                    targetObjectId: Uuid::v7(),
                    attributeCode: 'price',
                    after: ['value' => $i],
                );
            }
        })();

        self::assertSame(450, $port->materialize($batchId, 'agent', $drafts));
        self::assertSame(450, $port->countBatch($batchId));
        self::assertSame(450, $port->accept($batchId));
    }

    /**
     * @param iterable<mixed> $iterable
     *
     * @return Iterator<mixed>
     */
    private function toIterator(iterable $iterable): Iterator
    {
        return \is_array($iterable) ? new ArrayIterator($iterable) : new IteratorIterator($iterable);
    }

    private function port(): PendingChangesPort
    {
        return self::getContainer()->get(PendingChangesPort::class);
    }

    private function activateTenantFilter(Tenant $tenant): void
    {
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function createTenant(string $code): Tenant
    {
        $tenant = new Tenant($code, ucfirst($code).' Tenant');
        $em = $this->em();
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }
}
