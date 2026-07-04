<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\Rfc7807ExceptionListener;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * #2221 — deterministic contract for the storage-outage branch of
 * {@see Rfc7807ExceptionListener}: an uncaught Flysystem exception on a
 * custom `/api/*` route becomes a 503 `application/problem+json`, never the
 * stock HTML 500, and the Flysystem message (which embeds storage paths)
 * never reaches the payload.
 */
final class Rfc7807StorageOutageListenerTest extends TestCase
{
    #[Test]
    public function mapsFilesystemExceptionOnCustomApiRouteTo503ProblemJson(): void
    {
        $event = $this->eventFor(Request::create('/api/import-sessions/parse-preview', 'POST'));

        new Rfc7807ExceptionListener()->onException($event);

        $response = $event->getResponse();
        self::assertNotNull($response, 'the listener must claim the exception before HTML rendering');
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('content-type'));

        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame(503, $payload['status']);
        $detail = $payload['detail'];
        self::assertIsString($detail);
        self::assertStringNotContainsString(
            'secret/storage/key.csv',
            $detail,
            'the Flysystem message embeds storage keys and must never surface',
        );
    }

    #[Test]
    public function leavesFilesystemExceptionOutsideApiPathsAlone(): void
    {
        $event = $this->eventFor(Request::create('/admin/some-page', 'GET'));

        new Rfc7807ExceptionListener()->onException($event);

        self::assertNull($event->getResponse(), 'non-/api paths keep Symfony error handling (HTML profiler in dev)');
    }

    #[Test]
    public function leavesApiPlatformManagedRequestsAlone(): void
    {
        $request = Request::create('/api/attributes', 'POST');
        $request->attributes->set('_api_resource_class', 'App\\Catalog\\Domain\\Entity\\Attribute');
        $event = $this->eventFor($request);

        new Rfc7807ExceptionListener()->onException($event);

        self::assertNull($event->getResponse(), 'API Platform routes keep their native error pipeline');
    }

    private function eventFor(Request $request): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            UnableToWriteFile::atLocation('secret/storage/key.csv', 'connection refused'),
        );
    }
}
