<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Observability;

use App\Shared\Infrastructure\Observability\CorrelationIdContext;
use App\Shared\Infrastructure\Observability\CorrelationIdHttpListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Uid\Uuid;

final class CorrelationIdHttpListenerTest extends TestCase
{
    public function testReturnsAcceptedRequestIdAndClearsItAfterTerminate(): void
    {
        $context = new CorrelationIdContext();
        $listener = new CorrelationIdHttpListener($context);
        $kernel = $this->createStub(KernelInterface::class);
        $request = Request::create('/api/health');
        $request->headers->set(CorrelationIdContext::HEADER_NAME, 'pilot-import-42');

        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $response = new Response();
        $listener->onKernelResponse(new ResponseEvent(
            $kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));

        self::assertSame('pilot-import-42', $request->attributes->get(CorrelationIdContext::LOG_FIELD));
        self::assertSame('pilot-import-42', $response->headers->get(CorrelationIdContext::HEADER_NAME));

        $listener->onKernelTerminate(new TerminateEvent($kernel, $request, $response));
        self::assertNull($context->get());
    }

    public function testReplacesUnsafeRequestIdInResponseHeader(): void
    {
        $context = new CorrelationIdContext();
        $listener = new CorrelationIdHttpListener($context);
        $kernel = $this->createStub(KernelInterface::class);
        $request = Request::create('/api/health');
        $request->headers->set(CorrelationIdContext::HEADER_NAME, 'unsafe request id');

        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $response = new Response();
        $listener->onKernelResponse(new ResponseEvent(
            $kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));

        $returned = $response->headers->get(CorrelationIdContext::HEADER_NAME);
        self::assertNotSame('unsafe request id', $returned);
        self::assertIsString($returned);
        self::assertTrue(Uuid::isValid($returned));
    }

    public function testIgnoresSubrequests(): void
    {
        $context = new CorrelationIdContext();
        $listener = new CorrelationIdHttpListener($context);
        $kernel = $this->createStub(KernelInterface::class);
        $request = Request::create('/fragment');

        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST));

        self::assertNull($context->get());
        self::assertFalse($request->attributes->has(CorrelationIdContext::LOG_FIELD));
    }
}
