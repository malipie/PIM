<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Catalog;

use App\Export\Catalog\Infrastructure\Renderer\CatalogPdfRendererFactory;
use App\Export\Catalog\Infrastructure\Renderer\DompdfRenderer;
use App\Export\Catalog\Infrastructure\Renderer\GotenbergRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * CPDF-P6-03 — the renderer switch: gotenberg activates ONLY when both
 * CATALOG_PDF_RENDERER=gotenberg and a non-empty GOTENBERG_URL are set;
 * every other combination falls back to the in-process Dompdf default.
 */
final class CatalogPdfRendererFactoryTest extends TestCase
{
    #[Test]
    public function gotenbergActivatesWithSwitchAndUrl(): void
    {
        $factory = new CatalogPdfRendererFactory(
            new DompdfRenderer(),
            new MockHttpClient(),
            renderer: 'gotenberg',
            gotenbergUrl: 'http://gotenberg:3000',
        );

        self::assertInstanceOf(GotenbergRenderer::class, $factory->create());
    }

    /**
     * @return iterable<string, array{0: ?string, 1: ?string}>
     */
    public static function dompdfFallbacks(): iterable
    {
        yield 'defaults (nothing set)' => [null, null];
        yield 'explicit dompdf' => ['dompdf', null];
        yield 'gotenberg without URL' => ['gotenberg', null];
        yield 'gotenberg with empty URL' => ['gotenberg', ''];
        yield 'URL without the switch' => ['dompdf', 'http://gotenberg:3000'];
    }

    #[Test]
    #[DataProvider('dompdfFallbacks')]
    public function everyOtherCombinationFallsBackToDompdf(?string $renderer, ?string $url): void
    {
        $dompdf = new DompdfRenderer();
        $factory = new CatalogPdfRendererFactory(
            $dompdf,
            new MockHttpClient(),
            renderer: $renderer,
            gotenbergUrl: $url,
        );

        self::assertSame($dompdf, $factory->create());
    }
}
