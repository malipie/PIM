<?php

declare(strict_types=1);

namespace App\Tests\Integration\Export\Catalog;

use App\Export\Catalog\Infrastructure\Renderer\GotenbergRenderer;
use App\Export\Contracts\PdfRenderOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * CPDF-P6-03 — conditional integration against a REAL Gotenberg sidecar:
 * skipped unless GOTENBERG_URL is exported (the sidecar is opt-in via
 * `docker compose --profile gotenberg`, so CI and stock installs skip this).
 */
final class GotenbergRendererLiveTest extends TestCase
{
    #[Test]
    public function rendersARealPdfThroughTheSidecar(): void
    {
        $url = $_SERVER['GOTENBERG_URL'] ?? getenv('GOTENBERG_URL');
        if (!\is_string($url) || '' === $url) {
            self::markTestSkipped('GOTENBERG_URL is not set — the Gotenberg sidecar is opt-in.');
        }

        $renderer = new GotenbergRenderer(HttpClient::create(), $url);

        $pdf = $renderer->render(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><h1>Gotenberg smoke</h1></body></html>',
            new PdfRenderOptions(),
        );

        self::assertStringStartsWith('%PDF-', $pdf);
    }
}
