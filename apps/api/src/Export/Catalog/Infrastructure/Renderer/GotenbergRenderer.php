<?php

declare(strict_types=1);

namespace App\Export\Catalog\Infrastructure\Renderer;

use App\Export\Contracts\PdfRenderer;
use App\Export\Contracts\PdfRenderException;
use App\Export\Contracts\PdfRenderOptions;
use Closure;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * PDF renderer backed by a Gotenberg sidecar (ADR-0027, CPDF-P6-03): the HTML
 * document is POSTed to Gotenberg's Chromium HTML route and rendered with a
 * full CSS3 / CSS Paged Media engine (margin boxes, target-counter TOC page
 * numbers — the `premium` template features Dompdf cannot do).
 *
 * Opt-in: constructed by {@see CatalogPdfRendererFactory} only when
 * `CATALOG_PDF_RENDERER=gotenberg` and `GOTENBERG_URL` are set — a stock
 * Cortex install keeps the zero-infra Dompdf default. This is the only class
 * in the repo that touches the Gotenberg API.
 *
 * Chromium fetches the document's image URLs itself (decision B — images ship
 * as URLs, not data URIs), so the sidecar needs network reach to the asset
 * host. Transient failures (429/5xx/transport) retry with exponential backoff,
 * the Shopify throttling pattern (§7.3): 2^attempt seconds, MAX_ATTEMPTS then
 * {@see PdfRenderException}.
 */
final class GotenbergRenderer implements PdfRenderer
{
    private const int MAX_ATTEMPTS = 3;
    private const int BACKOFF_CAP_SECONDS = 8;

    /** Paper sizes in inches (Gotenberg takes dimensions, not names). */
    private const array PAPER_SIZES = [
        'A4' => ['8.27', '11.7'],
        'letter' => ['8.5', '11'],
    ];

    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /**
     * @param Closure(int): void|null $sleep test seam — replaces the real
     *                                       between-attempts sleep
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly float $timeout = 30.0,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function render(string $html, PdfRenderOptions $options): string
    {
        [$paperWidth, $paperHeight] = self::PAPER_SIZES[$options->paperSize] ?? self::PAPER_SIZES['A4'];

        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            // FormDataPart bodies are single-use iterables — rebuild per attempt.
            $form = new FormDataPart([
                'files' => new DataPart($html, 'index.html', 'text/html'),
                'paperWidth' => $paperWidth,
                'paperHeight' => $paperHeight,
                'landscape' => 'landscape' === $options->orientation ? 'true' : 'false',
                // Let the template's @page rules win over the form dimensions.
                'preferCssPageSize' => 'true',
            ]);

            try {
                $response = $this->httpClient->request(
                    'POST',
                    rtrim($this->baseUrl, '/').'/forms/chromium/convert/html',
                    [
                        'headers' => $form->getPreparedHeaders()->toArray(),
                        'body' => $form->bodyToIterable(),
                        'timeout' => $this->timeout,
                    ],
                );
                $status = $response->getStatusCode();

                if (200 === $status) {
                    $pdf = $response->getContent();
                    if (!str_starts_with($pdf, '%PDF-')) {
                        throw new PdfRenderException('Gotenberg returned a non-PDF response body.');
                    }

                    return $pdf;
                }

                if (429 === $status || $status >= 500) {
                    // Transient — back off and retry (throttled / sidecar hiccup).
                    $lastError = new TransportException(sprintf('Gotenberg responded HTTP %d.', $status));
                    $this->backOff($attempt);
                    continue;
                }

                // 4xx other than 429 will not heal on retry — fail fast.
                throw new PdfRenderException(sprintf('Gotenberg rejected the document (HTTP %d).', $status));
            } catch (HttpClientExceptionInterface $error) {
                $lastError = $error;
                if ($attempt < self::MAX_ATTEMPTS) {
                    $this->backOff($attempt);
                }
            }
        }

        throw new PdfRenderException(
            sprintf('Gotenberg rendering failed after %d attempts.', self::MAX_ATTEMPTS),
            0,
            $lastError,
        );
    }

    private function backOff(int $attempt): void
    {
        ($this->sleep)(min(2 ** $attempt, self::BACKOFF_CAP_SECONDS));
    }
}
