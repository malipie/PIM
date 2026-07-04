<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Drops duplicate-handle attempts on top of Doctrine's at-least-once
 * delivery semantics (sync now, async transports in Faza 1+). Logic:
 *
 *   1. On a worker run (ConsumedByWorkerStamp present), look up the
 *      envelope's TransportMessageIdStamp.
 *   2. INSERT the id into processed_messages with handler_class set to
 *      the receiver tag — Postgres' UNIQUE constraint on message_id
 *      surfaces a UniqueConstraintViolationException if the same id
 *      was already handled, in which case the middleware short-circuits.
 *   3. Otherwise it forwards the envelope down the stack and lets the
 *      handler run normally.
 *
 * Synchronous dispatches (no ConsumedByWorkerStamp) skip the middleware
 * entirely — sync handlers run inside the originating transaction and
 * are idempotent by construction (a single commit cannot replay).
 *
 * AUD-035 (W2-10) — `processed_messages` is provisioned by a raw-SQL
 * migration, but a worker with `auto_setup=1` only creates
 * `messenger_messages`. On a fresh deploy where the worker boots before
 * `doctrine:migrations:migrate`, the INSERT below would raise Postgres
 * `42P01` (undefined table) and dead-letter the whole queue. The middleware
 * self-heals: on the first `TableNotFoundException` it creates the table on
 * demand (idempotent `CREATE TABLE IF NOT EXISTS`, matching the migration
 * DDL) and retries. The create is attempted once per worker process (a
 * static flag keeps the steady-state path a single INSERT).
 *
 * NOTE (#2136) — the self-heal only works where the runtime connection can
 * issue DDL. Under the W1-1 privilege split the runtime role `pim_app` has
 * NO DDL, so the on-demand `CREATE TABLE` raises `42501` (permission denied
 * for schema public) rather than succeeding. On such a deploy the table +
 * grant are the OWNER's responsibility — migration Version20260704120000
 * (idempotent `CREATE ... IF NOT EXISTS` + `GRANT ... TO pim_app`) is the
 * source of truth; the self-heal is only a fallback for single-role
 * sandboxes (CI PHPUnit, throwaway) where the runtime role also owns DDL.
 */
final class IdempotencyMiddleware implements MiddlewareInterface
{
    /**
     * Set once the table has been ensured in this PHP process (worker boot),
     * so the steady-state path stays a single INSERT with no extra round-trip.
     */
    private static bool $tableEnsured = false;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $consumed = $envelope->last(ConsumedByWorkerStamp::class);
        if (null === $consumed) {
            return $stack->next()->handle($envelope, $stack);
        }

        $idStamp = $envelope->last(TransportMessageIdStamp::class);
        if (null === $idStamp) {
            return $stack->next()->handle($envelope, $stack);
        }

        $rawId = $idStamp->getId();
        if (!\is_string($rawId) && !\is_int($rawId)) {
            return $stack->next()->handle($envelope, $stack);
        }
        $messageId = (string) $rawId;
        if ('' === $messageId) {
            return $stack->next()->handle($envelope, $stack);
        }
        // IMP2-1.6a (#1468): the doctrine transport hands out sequential
        // bigint delivery ids, not UUIDs — the processed_messages PK is
        // UUID, so derive a stable v5 from the id (sync/AMQP UUID ids pass
        // through untouched).
        if (!Uuid::isValid($messageId)) {
            $messageId = Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_OID), 'messenger:'.$messageId)->toRfc4122();
        }

        try {
            $this->recordProcessed($messageId, $envelope->getMessage()::class);
        } catch (UniqueConstraintViolationException) {
            // Already processed — short-circuit, return envelope as-is.
            return $envelope;
        }

        return $stack->next()->handle($envelope, $stack);
    }

    /**
     * INSERT the envelope id, self-healing a missing `processed_messages` on a
     * worker-booted-before-migrate deploy (AUD-035). A {@see TableNotFoundException}
     * is recovered exactly once: create the table (idempotent) and retry; a
     * second miss is a real fault and propagates.
     */
    private function recordProcessed(string $messageId, string $handlerClass): void
    {
        $row = [
            'message_id' => $messageId,
            'handler_class' => $handlerClass,
            'processed_at' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
        ];

        try {
            $this->connection->insert('processed_messages', $row);
        } catch (TableNotFoundException) {
            $this->ensureProcessedMessagesTable();
            $this->connection->insert('processed_messages', $row);
        }
    }

    /**
     * Create `processed_messages` on demand. DDL is kept 1:1 with migration
     * Version20260429170000 (the authoritative schema); `IF NOT EXISTS` keeps
     * it safe under concurrent workers racing the same self-heal.
     */
    private function ensureProcessedMessagesTable(): void
    {
        if (self::$tableEnsured) {
            return;
        }

        // tenant-safe: infrastructure DDL — processed_messages is a global
        // messenger bookkeeping table with no tenant_id (cross-tenant by design).
        $this->connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS processed_messages (
                    message_id UUID PRIMARY KEY,
                    handler_class VARCHAR(255) NOT NULL,
                    processed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
                )
            SQL);
        $this->connection->executeStatement('CREATE INDEX IF NOT EXISTS processed_messages_handler_idx ON processed_messages (handler_class)');
        $this->connection->executeStatement('CREATE INDEX IF NOT EXISTS processed_messages_processed_at_idx ON processed_messages (processed_at)');

        self::$tableEnsured = true;
    }
}
