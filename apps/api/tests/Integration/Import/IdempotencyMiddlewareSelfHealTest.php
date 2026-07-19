<?php

declare(strict_types=1);

namespace App\Tests\Integration\Import;

use App\Shared\Infrastructure\Messenger\IdempotencyMiddleware;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Uid\Uuid;

/**
 * AUD-035 (W2-10) — {@see IdempotencyMiddleware} writes to `processed_messages`,
 * a table created by a raw-SQL migration. A worker with `auto_setup=1` only
 * provisions `messenger_messages`; on a fresh deploy where the worker boots
 * before `doctrine:migrations:migrate`, the INSERT used to raise Postgres
 * `42P01` (undefined table) and dead-letter the entire import queue.
 *
 * The middleware now self-heals: it creates the table on demand (idempotent
 * `CREATE TABLE IF NOT EXISTS`) and retries the INSERT, so the message is
 * processed regardless of deploy ordering.
 *
 * The test DB is built from ORM metadata (Foundry schema:update) and
 * `processed_messages` is NOT an ORM entity, so it is absent there — `DROP
 * TABLE IF EXISTS` makes the precondition explicit and the test reproducible
 * even if a future fixture provisions it.
 */
final class IdempotencyMiddlewareSelfHealTest extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The "table ensured" guard is a per-process static; reset it so each
        // test reproduces the cold worker-boot race regardless of order.
        $flag = new ReflectionProperty(IdempotencyMiddleware::class, 'tableEnsured');
        $flag->setValue(null, false);
    }

    #[Test]
    public function missingProcessedMessagesTableIsSelfHealedAndMessageProcessed(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('DROP TABLE IF EXISTS processed_messages');
        self::assertNull(
            $connection->fetchOne("SELECT to_regclass('public.processed_messages')"),
            'precondition: the table must be absent so the worker-boot-before-migrate race is reproduced',
        );

        $middleware = new IdempotencyMiddleware($connection);

        $reached = new stdClass();
        $reached->value = false;

        // A doctrine-transport delivery id is a bigint, not a UUID — exactly the
        // shape that hits the processed_messages INSERT on the worker path.
        $envelope = new Envelope(new stdClass())
            ->with(new ConsumedByWorkerStamp())
            ->with(new TransportMessageIdStamp('42'));

        $result = $middleware->handle($envelope, $this->terminalStack($reached));

        self::assertTrue($reached->value, 'the handler must run — a self-heal must not swallow the message');
        self::assertSame($envelope, $result);
        self::assertNotNull(
            $connection->fetchOne("SELECT to_regclass('public.processed_messages')"),
            'the middleware must have created processed_messages on demand',
        );
        self::assertSame(
            1,
            $this->countProcessedMessages($connection),
            'the envelope id must be recorded after the self-heal',
        );
    }

    #[Test]
    public function duplicateDeliveryStillShortCircuitsAfterSelfHeal(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('DROP TABLE IF EXISTS processed_messages');

        $middleware = new IdempotencyMiddleware($connection);

        $messageId = Uuid::v7()->toRfc4122();
        $first = new stdClass();
        $first->value = false;
        $second = new stdClass();
        $second->value = false;

        $envelope = new Envelope(new stdClass())
            ->with(new ConsumedByWorkerStamp())
            ->with(new TransportMessageIdStamp($messageId));

        $middleware->handle($envelope, $this->terminalStack($first));
        $middleware->handle($envelope, $this->terminalStack($second));

        self::assertTrue($first->value, 'first delivery must reach the handler');
        self::assertFalse($second->value, 'duplicate delivery must be short-circuited by the UNIQUE PK');
        self::assertSame(
            1,
            $this->countProcessedMessages($connection),
            'idempotency PK must still hold one row after the self-heal',
        );
    }

    /**
     * #2627 — the marker used to be INSERTed BEFORE the handler ran, so a
     * handler crash (or a dead worker) left it behind and every redelivery
     * short-circuited into a silent ack: the message was swallowed forever
     * (observed live: 5 PDF catalog runs stuck `pending`). The marker must
     * be written only after the handler chain returns.
     */
    #[Test]
    public function handlerCrashLeavesNoMarkerSoRedeliveryRunsHandler(): void
    {
        $connection = $this->connection();
        $middleware = new IdempotencyMiddleware($connection);

        $messageId = Uuid::v7()->toRfc4122();
        $envelope = new Envelope(new stdClass())
            ->with(new ConsumedByWorkerStamp())
            ->with(new TransportMessageIdStamp($messageId));

        $thrown = false;
        try {
            $middleware->handle($envelope, $this->throwingStack());
        } catch (RuntimeException) {
            $thrown = true;
        }
        self::assertTrue($thrown, 'the handler failure must propagate (retry/failure-transport semantics)');
        self::assertSame(
            0,
            $this->countMarker($connection, $messageId),
            'a crashed handling must NOT leave a processed marker behind',
        );

        $redelivery = new stdClass();
        $redelivery->value = false;
        $middleware->handle($envelope, $this->terminalStack($redelivery));
        self::assertTrue($redelivery->value, 'redelivery after a crash must run the handler');
        self::assertSame(1, $this->countMarker($connection, $messageId), 'a successful handling records the marker');

        $duplicate = new stdClass();
        $duplicate->value = false;
        $middleware->handle($envelope, $this->terminalStack($duplicate));
        self::assertFalse($duplicate->value, 'a completed message must still short-circuit duplicates');
    }

    private function countMarker(Connection $connection, string $messageId): int
    {
        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM processed_messages WHERE message_id = :id',
            ['id' => $messageId],
        );

        return (int) (\is_scalar($count) ? $count : 0);
    }

    /**
     * A stack whose terminal handler always throws — the crash scenario.
     */
    private function throwingStack(): StackInterface
    {
        $terminal = new class implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw new RuntimeException('handler exploded mid-message');
            }
        };

        return new class($terminal) implements StackInterface {
            public function __construct(private readonly MiddlewareInterface $next)
            {
            }

            public function next(): MiddlewareInterface
            {
                return $this->next;
            }
        };
    }

    private function connection(): Connection
    {
        // The container PHPStan extension already types this service id as
        // Connection (an assert here is flagged always-true), so return as-is.
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');

        return $connection;
    }

    private function countProcessedMessages(Connection $connection): int
    {
        $count = $connection->fetchOne('SELECT COUNT(*) FROM processed_messages');

        return (int) (\is_scalar($count) ? $count : 0);
    }

    /**
     * A one-middleware stack whose terminal handler flags that it ran.
     */
    private function terminalStack(stdClass $reached): StackInterface
    {
        $terminal = new class($reached) implements MiddlewareInterface {
            public function __construct(private readonly stdClass $reached)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $this->reached->value = true;

                return $envelope;
            }
        };

        return new class($terminal) implements StackInterface {
            public function __construct(private readonly MiddlewareInterface $next)
            {
            }

            public function next(): MiddlewareInterface
            {
                return $this->next;
            }
        };
    }
}
