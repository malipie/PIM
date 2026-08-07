<?php

declare(strict_types=1);

namespace App\Export\Catalog\Infrastructure\Renderer;

use App\Export\Contracts\PdfRenderer;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Resolves the active {@see PdfRenderer} adapter (ADR-0027, CPDF-P6-03).
 *
 * `CATALOG_PDF_RENDERER=gotenberg` + a non-empty `GOTENBERG_URL` activate the
 * headless-Chromium sidecar; anything else — including a requested gotenberg
 * with no URL configured — falls back to the zero-infra in-process Dompdf
 * default, so a stock Harmon install never needs the sidecar (R3).
 */
final class CatalogPdfRendererFactory
{
    public function __construct(
        private readonly DompdfRenderer $dompdf,
        private readonly HttpClientInterface $httpClient,
        private readonly ?string $renderer = null,
        private readonly ?string $gotenbergUrl = null,
    ) {
    }

    public function create(): PdfRenderer
    {
        if ('gotenberg' === $this->renderer && null !== $this->gotenbergUrl && '' !== $this->gotenbergUrl) {
            return new GotenbergRenderer($this->httpClient, $this->gotenbergUrl);
        }

        return $this->dompdf;
    }
}
