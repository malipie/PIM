<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Import\Application\Service\ImportThroughputCalculator;
use App\Import\Application\Service\ThroughputSnapshot;
use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Enum\ImportSessionStatus;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Uid\Uuid;

/**
 * VIEW-IMP-01 — unit-level math for the throughput probe. Uses the
 * public `aggregate()` helper so we don't need a database to assert
 * the rolling-window contract.
 */
final class ImportThroughputCalculatorTest extends TestCase
{
    public function testRejectsWindowBelowOne(): void
    {
        $calculator = new ImportThroughputCalculator($this->createStub(EntityManagerInterface::class));
        $this->expectException(InvalidArgumentException::class);
        $calculator->calculate($this->tenant(), Uuid::v7(), 0);
    }

    public function testRejectsWindowOver60(): void
    {
        $calculator = new ImportThroughputCalculator($this->createStub(EntityManagerInterface::class));
        $this->expectException(InvalidArgumentException::class);
        $calculator->calculate($this->tenant(), Uuid::v7(), 61);
    }

    public function testAggregateReturnsZeroWhenEmpty(): void
    {
        $now = new DateTimeImmutable('2026-05-11T12:00:00+00:00');
        $cutoff = $now->modify('-5 minutes');

        $snapshot = ImportThroughputCalculator::aggregate([], $cutoff, $now, 5);

        self::assertInstanceOf(ThroughputSnapshot::class, $snapshot);
        self::assertSame(0.0, $snapshot->rowsPerSec);
        self::assertSame(0, $snapshot->activeSessions);
        self::assertSame(5, $snapshot->windowMin);
        self::assertSame($now, $snapshot->sampledAt);
    }

    public function testAggregatesProcessedRowsAcrossActiveSessions(): void
    {
        $now = new DateTimeImmutable('2026-05-11T12:00:00+00:00');
        $cutoff = $now->modify('-10 minutes');

        $tenant = $this->tenant();
        $userId = Uuid::v7();
        // 600 rows over 60s real elapsed time (started 60s ago, within cutoff).
        $sessionA = $this->session($tenant, $userId, successCount: 600, errorCount: 0, startedAt: $now->modify('-60 seconds'));
        // 400 rows over 200s elapsed (started 200s ago, within cutoff).
        $sessionB = $this->session($tenant, $userId, successCount: 300, errorCount: 100, startedAt: $now->modify('-200 seconds'));

        $snapshot = ImportThroughputCalculator::aggregate([$sessionA, $sessionB], $cutoff, $now, 10);

        // Aggregated: (600 + 400) / (60 + 200) ≈ 3.846 rows/sec.
        self::assertSame(2, $snapshot->activeSessions);
        self::assertEqualsWithDelta(3.85, $snapshot->rowsPerSec, 0.05);
    }

    public function testAggregateClampsElapsedToCutoffForLongRunningSession(): void
    {
        $now = new DateTimeImmutable('2026-05-11T12:00:00+00:00');
        $cutoff = $now->modify('-5 minutes');

        $tenant = $this->tenant();
        $userId = Uuid::v7();
        // Started an hour ago — must be clamped to cutoff (5 minutes window).
        $session = $this->session($tenant, $userId, successCount: 3000, errorCount: 0, startedAt: $now->modify('-1 hour'));

        $snapshot = ImportThroughputCalculator::aggregate([$session], $cutoff, $now, 5);

        // 3000 rows / 300s (clamped to cutoff) = 10 rows/sec.
        self::assertEqualsWithDelta(10.0, $snapshot->rowsPerSec, 0.1);
    }

    public function testAggregateSkipsSessionsWithoutStartedAt(): void
    {
        $now = new DateTimeImmutable('2026-05-11T12:00:00+00:00');
        $cutoff = $now->modify('-5 minutes');

        $session = $this->session($this->tenant(), Uuid::v7(), successCount: 100, errorCount: 0, startedAt: null);

        $snapshot = ImportThroughputCalculator::aggregate([$session], $cutoff, $now, 5);

        self::assertSame(0.0, $snapshot->rowsPerSec);
        self::assertSame(1, $snapshot->activeSessions, 'Count of active sessions stays unchanged for telemetry.');
    }

    private function tenant(): Tenant
    {
        return new Tenant('demo', 'Demo', null);
    }

    private function session(
        Tenant $tenant,
        Uuid $userId,
        int $successCount,
        int $errorCount,
        ?DateTimeImmutable $startedAt,
    ): ImportSession {
        $reflection = new ReflectionClass(ImportSession::class);
        $session = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('id')->setValue($session, Uuid::v7());
        $reflection->getProperty('tenant')->setValue($session, $tenant);
        $reflection->getProperty('userId')->setValue($session, $userId);
        $reflection->getProperty('status')->setValue($session, ImportSessionStatus::Running->value);
        $reflection->getProperty('successCount')->setValue($session, $successCount);
        $reflection->getProperty('errorCount')->setValue($session, $errorCount);
        $reflection->getProperty('startedAt')->setValue($session, $startedAt);

        return $session;
    }
}
