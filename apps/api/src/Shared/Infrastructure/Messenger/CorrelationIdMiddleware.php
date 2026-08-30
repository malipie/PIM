<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Messenger\Stamp\CorrelationIdStamp;
use App\Shared\Infrastructure\Observability\CorrelationIdContext;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;

/** Propagates the active correlation id and binds it when a worker handles a message. */
final readonly class CorrelationIdMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CorrelationIdContext $context,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $consumedByWorker = null !== $envelope->last(ConsumedByWorkerStamp::class);
        $stamp = $envelope->last(CorrelationIdStamp::class);
        if (!$stamp instanceof CorrelationIdStamp || !CorrelationIdContext::isValid($stamp->correlationId)) {
            // Legacy/corrupt queue rows must start a new trace rather than
            // falling back to any stale state in the long-lived worker.
            $correlationId = $consumedByWorker
                ? $this->context->initialize(null)
                : $this->context->getOrCreate();
            $stamp = new CorrelationIdStamp($correlationId);
            $envelope = $envelope
                ->withoutAll(CorrelationIdStamp::class)
                ->with($stamp);
        }

        if (!$consumedByWorker) {
            return $stack->next()->handle($envelope, $stack);
        }

        $this->context->set($stamp->correlationId);

        // Keep the id bound until Symfony's ResetServicesListener resets all
        // ResetInterface services after the worker acknowledges or rejects
        // the message. Clearing here would strip the id from Messenger's own
        // success, retry and failure records emitted after the bus returns.
        return $stack->next()->handle($envelope, $stack);
    }
}
