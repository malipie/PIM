<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Catalog;

use App\Export\Catalog\Infrastructure\Renderer\DompdfRenderer;
use App\Export\Contracts\PdfRenderOptions;
use PHPUnit\Framework\TestCase;

/**
 * CPDF-P0-03 — POC that the PdfRenderer port + Dompdf adapter turn HTML into a
 * valid PDF binary in-process.
 */
final class DompdfRendererTest extends TestCase
{
    public function testRendersHtmlToPdfBinary(): void
    {
        $renderer = new DompdfRenderer();
        $html = '<html><body><h1>Karta produktu</h1>'
            .'<table><tr><td>SKU</td><td>ABC-1</td></tr></table></body></html>';

        $pdf = $renderer->render($html, new PdfRenderOptions());

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('%%EOF', $pdf);
    }

    public function testRendersLandscapeLetter(): void
    {
        $renderer = new DompdfRenderer();

        $pdf = $renderer->render(
            '<p>hello</p>',
            new PdfRenderOptions(paperSize: 'letter', orientation: 'landscape'),
        );

        self::assertStringStartsWith('%PDF-', $pdf);
    }
}
