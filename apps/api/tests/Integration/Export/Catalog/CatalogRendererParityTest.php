<?php

declare(strict_types=1);

namespace App\Tests\Integration\Export\Catalog;

use App\Export\Catalog\Application\CatalogPdfChromeFactory;
use App\Export\Catalog\Infrastructure\Renderer\DompdfRenderer;
use App\Export\Catalog\Infrastructure\Renderer\GotenbergRenderer;
use App\Export\Catalog\Infrastructure\Template\CatalogPdfFontProvider;
use App\Export\Contracts\PdfRenderOptions;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\HttpClient;
use Twig\Environment;

/**
 * CPDF-P6-05 — snapshot parity between the two PdfRenderer adapters (R2): the
 * SAME archetype HTML rendered by Dompdf and by Gotenberg must agree on the
 * document's key structural properties (page count, non-empty binary), so the
 * safe-CSS templates ({@see \App\Tests\Unit\Export\Catalog\CatalogTemplateCssGuardTest})
 * cannot silently drift apart between engines.
 *
 * The Gotenberg half is CONDITIONAL — it needs the opt-in sidecar
 * (`docker compose --profile gotenberg`, GOTENBERG_URL exported), so CI and
 * stock installs skip the cross-engine assertions; run locally against the
 * live sidecar. The Dompdf half always runs and pins the deterministic
 * baseline (sheet = exactly one page per product).
 *
 * The premium grid (target-counter TOC page numbers) is Gotenberg-only by
 * design — asserted here, never compared against Dompdf.
 */
final class CatalogRendererParityTest extends KernelTestCase
{
    private const int SHEET_PRODUCTS = 6;
    private const int PRICELIST_ROWS = 80;

    #[Test]
    public function dompdfSheetBaselineIsOnePagePerProductPlusCover(): void
    {
        $pdf = new DompdfRenderer()->render($this->sheetHtml(), new PdfRenderOptions());

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame(
            self::SHEET_PRODUCTS + 1,
            $this->pageCount($pdf),
            'sheet renders the framed cover plus exactly one page per product (#2608)',
        );
    }

    #[Test]
    public function sheetPageCountMatchesAcrossEngines(): void
    {
        $gotenberg = $this->gotenbergOrSkip();

        $html = $this->sheetHtml();
        $dompdfPdf = new DompdfRenderer()->render($html, new PdfRenderOptions());
        $gotenbergPdf = $gotenberg->render($html, new PdfRenderOptions());

        self::assertStringStartsWith('%PDF-', $gotenbergPdf);
        // The sheet paginates by explicit page-break-after — both engines must
        // agree exactly.
        self::assertSame(
            $this->pageCount($dompdfPdf),
            $this->pageCount($gotenbergPdf),
            'the sheet archetype must paginate identically on both engines',
        );
    }

    #[Test]
    public function pricelistPaginationStaysWithinOnePageAcrossEngines(): void
    {
        $gotenberg = $this->gotenbergOrSkip();

        $html = $this->pricelistHtml();
        $dompdfPages = $this->pageCount(new DompdfRenderer()->render($html, new PdfRenderOptions()));
        $gotenbergPages = $this->pageCount($gotenberg->render($html, new PdfRenderOptions()));

        // Row flow depends on engine line metrics — a ±1 page drift is
        // acceptable, more means the template broke on one engine.
        self::assertGreaterThan(1, $dompdfPages, 'an 80-row price table must paginate');
        self::assertGreaterThan(1, $gotenbergPages);
        self::assertLessThanOrEqual(
            1,
            abs($dompdfPages - $gotenbergPages),
            sprintf('pricelist page counts diverged: Dompdf %d vs Gotenberg %d', $dompdfPages, $gotenbergPages),
        );
    }

    #[Test]
    public function premiumGridRendersOnGotenbergOnly(): void
    {
        $gotenberg = $this->gotenbergOrSkip();

        // premium=true unlocks target-counter TOC page numbers — the construct
        // the CSS guard bans from the Dompdf path.
        $html = $this->twig()->render('catalog/grid.html.twig', [
            'branding' => self::BRANDING,
            'products' => $this->products(9),
            'title' => 'Parity grid',
            'premium' => true,
        ]);
        self::assertStringContainsString('target-counter', $html);

        $pdf = $gotenberg->render($html, new PdfRenderOptions());

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThanOrEqual(3, $this->pageCount($pdf), 'cover + TOC + grid pages');
    }

    private const array BRANDING = [
        'color' => '#0ea5e9',
        'company_name' => 'ACME Parity',
        'logo' => null,
    ];

    private function sheetHtml(): string
    {
        $products = [];
        foreach ($this->products(self::SHEET_PRODUCTS) as $product) {
            $products[] = $product + [
                'image' => null,
                'description' => '<strong>Opis</strong>',
                'specs' => [['label' => 'Waga', 'value' => '2kg']],
            ];
        }

        return $this->twig()->render('catalog/sheet.html.twig', [
            'branding' => self::BRANDING,
            'products' => $products,
            ...$this->chrome(\count($products), 'Parity sheet'),
        ]);
    }

    private function pricelistHtml(): string
    {
        $products = [];
        for ($i = 1; $i <= self::PRICELIST_ROWS; ++$i) {
            $products[] = [
                'sku' => \sprintf('SKU-%03d', $i),
                'name' => \sprintf('Produkt %d', $i),
                'price' => '199,00 zł',
                'availability' => 'in stock',
            ];
        }

        return $this->twig()->render('catalog/pricelist.html.twig', [
            'branding' => self::BRANDING,
            'products' => $products,
            ...$this->chrome(\count($products), 'Parity pricelist'),
        ]);
    }

    /**
     * The real chrome (embedded fonts included) — parity must cover the
     * engines' font handling, not just bare markup (#2608).
     *
     * @return array<string, mixed>
     */
    private function chrome(int $itemCount, string $title): array
    {
        $factory = new CatalogPdfChromeFactory(
            new CatalogPdfFontProvider(\dirname(__DIR__, 4).'/assets/pdf-fonts'),
            new MockClock('2026-07-17T12:00:00+00:00'),
        );

        return $factory->chrome(self::BRANDING, null, $itemCount, $title);
    }

    /**
     * @return list<array{title: string, sku: string, price: string}>
     */
    private function products(int $count): array
    {
        $products = [];
        for ($i = 1; $i <= $count; ++$i) {
            $products[] = [
                'title' => \sprintf('Produkt %d', $i),
                'sku' => \sprintf('SKU-%03d', $i),
                'price' => '99,00 zł',
            ];
        }

        return $products;
    }

    private function twig(): Environment
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig;
    }

    private function gotenbergOrSkip(): GotenbergRenderer
    {
        $url = $_SERVER['GOTENBERG_URL'] ?? getenv('GOTENBERG_URL');
        if (!\is_string($url) || '' === $url) {
            self::markTestSkipped('GOTENBERG_URL is not set — cross-engine parity runs against the opt-in sidecar.');
        }

        return new GotenbergRenderer(HttpClient::create(), $url);
    }

    /**
     * Both engines emit one plain `/Type /Page` object per page (verified
     * empirically for Dompdf and Chromium/skia) plus a single `/Type /Pages`
     * tree node — the same heuristic CatalogRenderService uses.
     */
    private function pageCount(string $pdf): int
    {
        return substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages');
    }
}
