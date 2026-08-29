<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Audit;

use App\Shared\Infrastructure\Audit\AuditWriteFailedException;
use App\Shared\Infrastructure\Audit\AuditWriteGuard;
use DH\Auditor\Event\LifecycleEvent;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #3045 — the guard's contract: a connection that can no longer answer must
 * become a named failure, not a silent success.
 *
 * DH Auditor catches every exception its own INSERT throws
 * ({@see \DH\Auditor\EventSubscriber\AuditEventSubscriber}), and on Postgres
 * that leaves the transaction aborted: the COMMIT that follows is downgraded
 * to a silent ROLLBACK and the caller is told 201 for work that was discarded.
 *
 * Tested here rather than end to end on purpose. Two earlier attempts fought
 * the harness instead of the bug: a KernelTestCase could not work at all
 * (the auditor's provider is request-scoped and wrote no rows), and an API
 * test that hid the table with DDL surfaced a raw SQLSTATE[25P02] from an
 * unrelated listener. The guard's own contract — probe, translate, name the
 * table, and cost one probe per flush — is exactly what a double can pin.
 */
final class AuditWriteGuardTest extends TestCase
{
    #[Test]
    public function anUnusableConnectionBecomesANamedFailure(): void
    {
        $guard = new AuditWriteGuard($this->connectionThatFails());

        try {
            $guard->onAuditEvent($this->event('import_profiles_audit'));
            self::fail('a poisoned transaction must not pass silently');
        } catch (AuditWriteFailedException $failure) {
            self::assertSame('import_profiles_audit', $failure->auditTable);
            self::assertStringContainsString('import_profiles_audit', $failure->getMessage());
            self::assertStringContainsString('NOT persisted', $failure->getMessage());
        }
    }

    #[Test]
    public function aHealthyConnectionPassesThrough(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeQuery');

        new AuditWriteGuard($connection)->onAuditEvent($this->event('attributes_audit'));
    }

    #[Test]
    public function theProbeCostsOneRoundTripPerFlushNotPerAuditedRow(): void
    {
        // A bulk path writes thousands of audit rows inside one flush; paying a
        // round-trip for each would make the guard the expensive part.
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeQuery');

        $guard = new AuditWriteGuard($connection);
        for ($i = 0; $i < 50; ++$i) {
            $guard->onAuditEvent($this->event('attributes_audit'));
        }
    }

    #[Test]
    public function theNextFlushIsProbedAgain(): void
    {
        // The first version keyed this on the transaction nesting level, which
        // skipped the probe on exactly the case the guard exists for.
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('executeQuery');

        $guard = new AuditWriteGuard($connection);
        $guard->onAuditEvent($this->event('attributes_audit'));
        $guard->postFlush(new PostFlushEventArgs($this->createStub(EntityManagerInterface::class)));
        $guard->onAuditEvent($this->event('attributes_audit'));
    }

    private function connectionThatFails(): Connection
    {
        // Stub, not mock: this test asserts the translation, not the call.
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willThrowException(
            $this->createStub(DriverException::class),
        );

        return $connection;
    }

    /**
     * LifecycleEvent validates its payload against the auditor's own table
     * columns (SchemaHelper::isValidPayload), so every one of them has to be
     * present or the constructor refuses the event.
     */
    private function event(string $auditTable): LifecycleEvent
    {
        return new LifecycleEvent([
            'table' => $auditTable,
            'entity' => 'App\\Whatever',
            'type' => 'insert',
            'object_id' => '1',
            'discriminator' => null,
            'transaction_hash' => 'hash',
            'diffs' => '{}',
            'blame_id' => null,
            'blame_user' => null,
            'blame_user_fqdn' => null,
            'blame_user_firewall' => null,
            'ip' => null,
            'created_at' => '2026-08-29 00:00:00',
        ]);
    }
}
