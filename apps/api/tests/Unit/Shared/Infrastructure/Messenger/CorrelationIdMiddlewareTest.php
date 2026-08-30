<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Messenger\CorrelationIdMiddleware;
use App\Shared\Infrastructure\Messenger\Stamp\CorrelationIdStamp;
use App\Shared\Infrastructure\Observability\CorrelationIdContext;
use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Uid\Uuid;

final class CorrelationIdMiddlewareTest extends TestCase
{
    public function testAddsCurrentRequestIdToDispatchedEnvelope(): void
    {
        $context = new CorrelationIdContext();
        $context->set('request-42');
        $middleware = new CorrelationIdMiddleware($context);

        $result = $middleware->handle(new Envelope(new stdClass()), $this->makeStack());

        $stamp = $result->last(CorrelationIdStamp::class);
        self::assertInstanceOf(CorrelationIdStamp::class, $stamp);
        self::assertSame('request-42', $stamp->correlationId);
        self::assertSame('request-42', $context->get());
    }

    public function testGeneratesIdForDispatchOutsideHttpRequest(): void
    {
        $context = new CorrelationIdContext();
        $middleware = new CorrelationIdMiddleware($context);

        $result = $middleware->handle(new Envelope(new stdClass()), $this->makeStack());

        $stamp = $result->last(CorrelationIdStamp::class);
        self::assertInstanceOf(CorrelationIdStamp::class, $stamp);
        self::assertTrue(Uuid::isValid($stamp->correlationId));
        self::assertSame($stamp->correlationId, $context->get());
    }

    public function testBindsStampedIdThroughWorkerAcknowledgementAndFrameworkReset(): void
    {
        $context = new CorrelationIdContext();
        $context->set('stale-request');
        $middleware = new CorrelationIdMiddleware($context);
        $seenDuringHandling = null;
        $envelope = new Envelope(new stdClass())
            ->with(new CorrelationIdStamp('originating-request'))
            ->with(new ConsumedByWorkerStamp());

        $middleware->handle($envelope, $this->makeStack(
            static function () use ($context, &$seenDuringHandling): void {
                $seenDuringHandling = $context->get();
            },
        ));

        self::assertSame('originating-request', $seenDuringHandling);
        self::assertSame(
            'originating-request',
            $context->get(),
            'Messenger emits its acknowledgement log after the bus returns.',
        );
        $context->reset();
        self::assertNull($context->get());
    }

    public function testKeepsWorkerContextForFailureLogUntilFrameworkReset(): void
    {
        $context = new CorrelationIdContext();
        $middleware = new CorrelationIdMiddleware($context);
        $envelope = new Envelope(new stdClass())
            ->with(new CorrelationIdStamp('originating-request'))
            ->with(new ConsumedByWorkerStamp());

        try {
            $middleware->handle($envelope, $this->makeStack(
                static function (): void {
                    throw new RuntimeException('handler failed');
                },
            ));
            self::fail('The handler exception should propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('handler failed', $exception->getMessage());
        }

        self::assertSame('originating-request', $context->get());
        $context->reset();
        self::assertNull($context->get());
    }

    public function testReplacesUnsafeStampBeforeWorkerHandling(): void
    {
        $context = new CorrelationIdContext();
        $context->set('stale-request');
        $middleware = new CorrelationIdMiddleware($context);
        $handledEnvelope = null;
        $envelope = new Envelope(new stdClass())
            ->with(new CorrelationIdStamp("forged\nvalue"))
            ->with(new ConsumedByWorkerStamp());

        $middleware->handle($envelope, $this->makeStack(
            static function (Envelope $nextEnvelope) use (&$handledEnvelope): void {
                $handledEnvelope = $nextEnvelope;
            },
        ));

        self::assertInstanceOf(Envelope::class, $handledEnvelope);
        $stamp = $handledEnvelope->last(CorrelationIdStamp::class);
        self::assertInstanceOf(CorrelationIdStamp::class, $stamp);
        self::assertNotSame("forged\nvalue", $stamp->correlationId);
        self::assertNotSame('stale-request', $stamp->correlationId);
        self::assertTrue(CorrelationIdContext::isValid($stamp->correlationId));
    }

    /** @param (Closure(Envelope): void)|null $onHandle */
    private function makeStack(?Closure $onHandle = null): StackInterface
    {
        $next = new class implements MiddlewareInterface {
            /** @var (Closure(Envelope): void)|null */
            public ?Closure $onHandle = null;

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                if (null !== $this->onHandle) {
                    ($this->onHandle)($envelope);
                }

                return $envelope;
            }
        };
        $next->onHandle = $onHandle;

        return new class($next) implements StackInterface {
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
