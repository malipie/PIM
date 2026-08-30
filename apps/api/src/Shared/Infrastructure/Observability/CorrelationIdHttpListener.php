<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Establishes a trusted correlation id at the HTTP boundary and returns it.
 */
final readonly class CorrelationIdHttpListener
{
    public function __construct(
        private CorrelationIdContext $context,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 8192)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $correlationId = $this->context->initialize(
            $request->headers->get(CorrelationIdContext::HEADER_NAME),
        );
        $request->attributes->set(CorrelationIdContext::LOG_FIELD, $correlationId);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -4096)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getResponse()->headers->set(
            CorrelationIdContext::HEADER_NAME,
            $this->context->getOrCreate(),
        );
    }

    #[AsEventListener(event: KernelEvents::TERMINATE, priority: -4096)]
    public function onKernelTerminate(TerminateEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->context->reset();
        }
    }
}
