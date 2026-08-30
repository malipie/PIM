<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Infrastructure\Messenger\CorrelationIdMiddleware;
use App\Shared\Infrastructure\Messenger\Stamp\CorrelationIdStamp;
use App\Shared\Infrastructure\Observability\CorrelationIdContext;
use App\Shared\Infrastructure\Observability\CorrelationIdHttpListener;
use App\Shared\Infrastructure\Observability\CorrelationIdLogProcessor;
use Closure;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class CorrelationFlowTest extends KernelTestCase
{
    public function testHttpRequestIdPropagatesThroughMessengerToLogRecord(): void
    {
        $kernel = self::bootKernel();
        $container = self::getContainer();
        $context = $container->get(CorrelationIdContext::class);
        self::assertInstanceOf(CorrelationIdContext::class, $context);
        $listener = $container->get(CorrelationIdHttpListener::class);
        self::assertInstanceOf(CorrelationIdHttpListener::class, $listener);
        $request = Request::create('/api/import-sessions');
        $request->headers->set(CorrelationIdContext::HEADER_NAME, 'pilot-import-2026');
        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        $bus = $container->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $dispatched = $bus->dispatch(new stdClass());
        $stamp = $dispatched->last(CorrelationIdStamp::class);
        self::assertInstanceOf(CorrelationIdStamp::class, $stamp);
        self::assertSame('pilot-import-2026', $stamp->correlationId);

        // A real async worker boots without the originating HTTP context. The
        // serialized stamp is the only link between both processes.
        $serializer = $container->get(SerializerInterface::class);
        self::assertInstanceOf(SerializerInterface::class, $serializer);
        $workerEnvelope = $serializer->decode($serializer->encode($dispatched));
        $context->reset();
        $workerRecord = null;
        $middleware = $container->get(CorrelationIdMiddleware::class);
        self::assertInstanceOf(CorrelationIdMiddleware::class, $middleware);
        $processor = $container->get(CorrelationIdLogProcessor::class);
        self::assertInstanceOf(CorrelationIdLogProcessor::class, $processor);
        $workerEnvelope = $workerEnvelope->with(new ConsumedByWorkerStamp());

        $middleware->handle($workerEnvelope, $this->makeWorkerStack(
            static function () use ($processor, &$workerRecord): void {
                $workerRecord = $processor(new LogRecord(
                    new DateTimeImmutable(),
                    'app',
                    Level::Info,
                    'Import handler started',
                ));
            },
        ));

        self::assertInstanceOf(LogRecord::class, $workerRecord);
        self::assertSame('pilot-import-2026', $workerRecord->extra[CorrelationIdContext::LOG_FIELD]);
        self::assertSame('pilot-import-2026', $context->get(), 'Worker ack/failure logs still need the id.');
        $context->reset();
        self::assertNull($context->get(), 'Worker context must be clean before the next message.');
    }

    /** @param Closure(): void $onHandle */
    private function makeWorkerStack(Closure $onHandle): StackInterface
    {
        $next = new class($onHandle) implements MiddlewareInterface {
            /** @param Closure(): void $onHandle */
            public function __construct(private readonly Closure $onHandle)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                ($this->onHandle)();

                return $envelope;
            }
        };

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
