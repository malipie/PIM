<?php

declare(strict_types=1);

namespace App\Tests\Integration\Integration\Generic;

use App\Integration\Generic\Domain\Entity\Connection;
use App\Integration\Generic\Domain\Entity\SyncBinding;
use App\Integration\Generic\Domain\Entity\SyncRun;
use App\Integration\Generic\Domain\Entity\SyncRunLog;
use App\Integration\Generic\Domain\Enum\AuthType;
use App\Integration\Generic\Domain\Enum\SyncDirection;
use App\Integration\Generic\Domain\Enum\SyncRecordAction;
use App\Integration\Generic\Domain\Repository\ConnectionRepositoryInterface;
use App\Integration\Generic\Domain\Repository\SyncBindingRepositoryInterface;
use App\Integration\Generic\Domain\Repository\SyncRunLogRepositoryInterface;
use App\Integration\Generic\Domain\Repository\SyncRunRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * APIC-P3-02 — SyncRun + SyncRunLog round-trip (counters, cursor, per-record
 * log) + 2-tenant cross-read = 0 (Doctrine TenantFilter; Postgres RLS verified
 * at the migration level).
 */
final class SyncRunRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function persistsRunWithCountersAndPerRecordLog(): void
    {
        $alpha = $this->createTenant('alpha');
        $this->activateTenantFilter($alpha);
        $binding = $this->createBinding($alpha);

        $run = new SyncRun($binding, SyncDirection::Inbound);
        $run->assignTenant($alpha);
        $run->setCursorBefore(['state' => '2026-06-01']);
        $run->recordCreated();
        $run->recordFailed();
        $run->markFinished(null, ['state' => '2026-06-02']);
        $this->runs()->save($run);

        $log = new SyncRunLog($run, SyncRecordAction::Created);
        $log->assignTenant($alpha);
        $log->setMatchKey('A-1');
        $log->setFields(['sku' => 'A-1', 'name' => 'Widget']);
        $this->logs()->save($log);

        $foundRun = $this->runs()->findById($run->getId());
        self::assertNotNull($foundRun);
        self::assertSame(1, $foundRun->getCreatedCount());
        self::assertSame(1, $foundRun->getFailedCount());
        self::assertSame(['state' => '2026-06-01'], $foundRun->getCursorBefore());
        self::assertSame(['state' => '2026-06-02'], $foundRun->getCursorAfter());
        self::assertCount(1, $this->runs()->findByBinding($binding));

        $logs = $this->logs()->findByRun($run);
        self::assertCount(1, $logs);
        self::assertSame('A-1', $logs[0]->getMatchKey());
        self::assertSame(SyncRecordAction::Created, $logs[0]->getAction());
        self::assertSame(['sku' => 'A-1', 'name' => 'Widget'], $logs[0]->getFields());
    }

    #[Test]
    public function runsAreIsolatedAcrossTenants(): void
    {
        $alpha = $this->createTenant('alpha');
        $beta = $this->createTenant('beta');

        $this->activateTenantFilter($alpha);
        $binding = $this->createBinding($alpha);
        $run = new SyncRun($binding, SyncDirection::Inbound);
        $run->assignTenant($alpha);
        $this->runs()->save($run);

        $this->activateTenantFilter($beta);
        self::assertCount(0, $this->runs()->findByBinding($binding));
    }

    #[Test]
    public function findRunningByBindingHonoursStatusAndTimeFloor(): void
    {
        // #2722 — the redelivery guard: only a still-running, recent run of the
        // binding blocks a new one.
        $alpha = $this->createTenant('alpha');
        $this->activateTenantFilter($alpha);
        $binding = $this->createBinding($alpha);

        $run = new SyncRun($binding, SyncDirection::Outbound);
        $run->assignTenant($alpha);
        $this->runs()->save($run);

        $floor = new DateTimeImmutable('-6 hours');
        $found = $this->runs()->findRunningByBinding($binding, $floor);
        self::assertNotNull($found);
        self::assertTrue($run->getId()->equals($found->getId()));

        // A run older than the floor is treated as orphaned — it must not
        // block the binding.
        self::assertNull($this->runs()->findRunningByBinding($binding, new DateTimeImmutable('+1 minute')));

        // A finalized run never blocks.
        $run->markFinished();
        $this->runs()->save($run);
        self::assertNull($this->runs()->findRunningByBinding($binding, $floor));
    }

    private function runs(): SyncRunRepositoryInterface
    {
        return self::getContainer()->get(SyncRunRepositoryInterface::class);
    }

    private function logs(): SyncRunLogRepositoryInterface
    {
        return self::getContainer()->get(SyncRunLogRepositoryInterface::class);
    }

    private function bindings(): SyncBindingRepositoryInterface
    {
        return self::getContainer()->get(SyncBindingRepositoryInterface::class);
    }

    private function connections(): ConnectionRepositoryInterface
    {
        return self::getContainer()->get(ConnectionRepositoryInterface::class);
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function createBinding(Tenant $tenant): SyncBinding
    {
        $connection = new Connection('idosell', 'IdoSell', 'https://api.example.com', AuthType::ApiKey);
        $connection->assignTenant($tenant);
        $this->connections()->save($connection);

        $binding = new SyncBinding($connection, Uuid::v7());
        $binding->assignTenant($tenant);
        $this->bindings()->save($binding);

        return $binding;
    }

    private function activateTenantFilter(Tenant $tenant): void
    {
        $this->tenantContext()->set($tenant);
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
