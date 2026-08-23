<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Application\Message\ObjectValuesChangedMessage;
use App\Catalog\Infrastructure\Messenger\AttributesIndexedRebuildDeadLetterListener;
use App\Import\Domain\Message\ImportRunMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Uid\Uuid;

/**
 * AUD-039 / G-01 — the dead-letter listener makes a drifted rebuild LOUD: it
 * logs an error (with the affected ids) only on the FINAL failure of an
 * {@see ObjectValuesChangedMessage}, leaving retriable failures and unrelated
 * messages untouched.
 */
final class AttributesIndexedRebuildDeadLetterListenerTest extends TestCase
{
    #[Test]
    public function logsErrorOnFinalFailureOfRebuildMessage(): void
    {
        $logger = $this->errorCollectingLogger();
        $listener = new AttributesIndexedRebuildDeadLetterListener($logger);

        $ids = [Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122()];
        $event = new WorkerMessageFailedEvent(
            new Envelope(new ObjectValuesChangedMessage($ids, Uuid::v7())),
            'async',
            new RuntimeException('version conflict exhausted'),
        );
        // willRetry() defaults to false → this is the final failure.

        $listener($event);

        self::assertCount(1, $logger->errors);
        self::assertSame($ids, $logger->errors[0]['context']['object_ids'] ?? null);
        self::assertSame(2, $logger->errors[0]['context']['count'] ?? null);
        self::assertStringContainsString('detect-attributes-drift', $logger->errors[0]['message']);
    }

    #[Test]
    public function silentWhenMessageWillRetry(): void
    {
        $logger = $this->errorCollectingLogger();
        $listener = new AttributesIndexedRebuildDeadLetterListener($logger);

        $event = new WorkerMessageFailedEvent(
            new Envelope(new ObjectValuesChangedMessage([Uuid::v7()->toRfc4122()], Uuid::v7())),
            'async',
            new RuntimeException('transient'),
        );
        $event->setForRetry();

        $listener($event);

        self::assertCount(0, $logger->errors, 'retriable failures must not be logged as dead-lettered');
    }

    #[Test]
    public function ignoresUnrelatedMessages(): void
    {
        $logger = $this->errorCollectingLogger();
        $listener = new AttributesIndexedRebuildDeadLetterListener($logger);

        $event = new WorkerMessageFailedEvent(
            new Envelope(new ImportRunMessage(Uuid::v7(), Uuid::v7())),
            'import',
            new RuntimeException('unrelated'),
        );

        $listener($event);

        self::assertCount(0, $logger->errors, 'only ObjectValuesChangedMessage failures are handled here');
    }

    /**
     * @return AbstractLogger&object{errors: list<array{message: string, context: array<mixed>}>}
     */
    private function errorCollectingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<mixed>}> */
            public array $errors = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                if ('error' === $level) {
                    $this->errors[] = ['message' => (string) $message, 'context' => $context];
                }
            }
        };
    }
}
