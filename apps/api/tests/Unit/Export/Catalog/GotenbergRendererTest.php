<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Catalog;

use App\Export\Catalog\Infrastructure\Renderer\GotenbergRenderer;
use App\Export\Contracts\PdfRenderException;
use App\Export\Contracts\PdfRenderOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * CPDF-P6-03 — the Gotenberg adapter behind the PdfRenderer port: happy path
 * posts the HTML as multipart form-data to the Chromium route, transient
 * failures (429/5xx/transport) retry with backoff, permanent 4xx and non-PDF
 * bodies fail fast, and exhaustion raises PdfRenderException.
 */
final class GotenbergRendererTest extends TestCase
{
    private const string PDF = '%PDF-1.7 fake';

    /** @var list<int> */
    private array $sleeps = [];

    private function renderer(MockHttpClient $client): GotenbergRenderer
    {
        $this->sleeps = [];

        return new GotenbergRenderer(
            $client,
            'http://gotenberg:3000',
            sleep: function (int $seconds): void {
                $this->sleeps[] = $seconds;
            },
        );
    }

    #[Test]
    public function rendersHtmlThroughTheChromiumRoute(): void
    {
        $captured = ['method' => '', 'url' => '', 'contentType' => ''];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured) {
            $headers = $options['normalized_headers'] ?? null;
            $first = \is_array($headers) && \is_array($headers['content-type'] ?? null)
                ? ($headers['content-type'][0] ?? '')
                : '';
            $captured = [
                'method' => $method,
                'url' => $url,
                'contentType' => \is_string($first) ? $first : '',
            ];

            return new MockResponse(self::PDF);
        });

        $pdf = $this->renderer($client)->render('<html><body>x</body></html>', new PdfRenderOptions());

        self::assertSame(self::PDF, $pdf);
        self::assertSame('POST', $captured['method']);
        self::assertSame('http://gotenberg:3000/forms/chromium/convert/html', $captured['url']);
        self::assertStringContainsString('multipart/form-data', $captured['contentType']);
        self::assertSame([], $this->sleeps, 'no backoff on the happy path');
    }

    #[Test]
    public function retriesTransientServerErrorsWithBackoff(): void
    {
        $client = new MockHttpClient([
            new MockResponse('boom', ['http_code' => 503]),
            new MockResponse('slow down', ['http_code' => 429]),
            new MockResponse(self::PDF),
        ]);

        $pdf = $this->renderer($client)->render('<html></html>', new PdfRenderOptions());

        self::assertSame(self::PDF, $pdf);
        self::assertSame(3, $client->getRequestsCount());
        self::assertSame([2, 4], $this->sleeps, 'exponential 2^attempt backoff between attempts');
    }

    #[Test]
    public function failsAfterExhaustingRetries(): void
    {
        $client = new MockHttpClient([
            new MockResponse('boom', ['http_code' => 500]),
            new MockResponse('boom', ['http_code' => 500]),
            new MockResponse('boom', ['http_code' => 500]),
        ]);

        $this->expectException(PdfRenderException::class);
        $this->expectExceptionMessage('after 3 attempts');
        $this->renderer($client)->render('<html></html>', new PdfRenderOptions());
    }

    #[Test]
    public function permanentClientErrorFailsFastWithoutRetry(): void
    {
        $client = new MockHttpClient([new MockResponse('bad form', ['http_code' => 400])]);

        try {
            $this->renderer($client)->render('<html></html>', new PdfRenderOptions());
            self::fail('expected PdfRenderException');
        } catch (PdfRenderException $error) {
            self::assertStringContainsString('HTTP 400', $error->getMessage());
        }

        self::assertSame(1, $client->getRequestsCount(), 'a 400 must not be retried');
        self::assertSame([], $this->sleeps);
    }

    #[Test]
    public function nonPdfResponseBodyIsRejected(): void
    {
        $client = new MockHttpClient([new MockResponse('<html>not a pdf</html>')]);

        $this->expectException(PdfRenderException::class);
        $this->expectExceptionMessage('non-PDF');
        $this->renderer($client)->render('<html></html>', new PdfRenderOptions());
    }
}
