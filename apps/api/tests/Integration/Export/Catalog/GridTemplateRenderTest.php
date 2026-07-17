<?php

declare(strict_types=1);

namespace App\Tests\Integration\Export\Catalog;

use App\Export\Catalog\Application\CatalogPdfChromeFactory;
use App\Export\Catalog\Infrastructure\Template\CatalogPdfFontProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Twig\Environment;

/**
 * CPDF-P6-02 / #2608 — renders the grid archetype through the real Twig
 * environment (strict_variables ON): the framed cover carries the brand
 * palette + catalog title, the TWO-column TOC links every product to an
 * anchored card, the cards are chunked into 3-per-row table rows, and the
 * premium paged-media bits (target-counter TOC page numbers) only appear when
 * the `premium` flag is set — the graceful Dompdf degradation demanded by the
 * ticket.
 */
final class GridTemplateRenderTest extends KernelTestCase
{
    /**
     * @param list<array<string, string>> $products
     *
     * @return array<string, mixed>
     */
    private static function context(array $products): array
    {
        $branding = [
            'color' => '#0ea5e9',
            'company_name' => 'ACME',
            'logo' => null,
        ];
        $chrome = new CatalogPdfChromeFactory(
            new CatalogPdfFontProvider(\dirname(__DIR__, 4).'/assets/pdf-fonts'),
            new MockClock('2026-07-17T12:00:00+00:00'),
        );

        return [
            'branding' => $branding,
            'products' => $products,
            ...$chrome->chrome($branding, null, \count($products), 'Katalog wiosna'),
        ];
    }

    private function twig(): Environment
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig;
    }

    #[Test]
    public function rendersCoverTwoColumnTocAndAnchoredCards(): void
    {
        $products = [];
        for ($i = 1; $i <= 7; ++$i) {
            $products[] = [
                'title' => \sprintf('Produkt %d', $i),
                'sku' => \sprintf('SKU-%03d', $i),
                'image' => '',
                'price' => '99,00 zł',
            ];
        }

        $html = $this->twig()->render('catalog/grid.html.twig', self::context($products));

        // Cover: palette accent in <style>, catalog title + localised count.
        self::assertStringContainsString('#0ea5e9', $html);
        self::assertStringContainsString('Katalog wiosna', $html);
        self::assertStringContainsString('7 products', $html);
        self::assertStringContainsString('@font-face', $html);
        // TOC: one anchor per product (split across two columns), pointing at
        // the card ids — ceil(7/2) = 4 left + 3 right.
        self::assertSame(7, substr_count($html, 'href="#product-'));
        self::assertSame(7, substr_count($html, 'id="product-'));
        self::assertStringContainsString('href="#product-7"', $html);
        self::assertStringContainsString('id="product-7"', $html);
        self::assertSame(2, substr_count($html, 'class="toc-col toc-col-'));
        // Grid: every product renders one borderless card.
        self::assertSame(7, substr_count($html, 'class="card" id="product-'));
        // Page footer counter (CSS 2.1, Dompdf-supported).
        self::assertStringContainsString('counter(page)', $html);
        // No image mapped — the tinted panels carry the placeholder.
        self::assertStringContainsString('no image', $html);
    }

    #[Test]
    public function premiumPagedMediaIsGatedBehindTheFlag(): void
    {
        $twig = $this->twig();
        $products = [['title' => 'P', 'sku' => 'S', 'image' => '', 'price' => '']];

        // Default (Dompdf path): no target-counter — the TOC degrades to plain anchors.
        $plain = $twig->render('catalog/grid.html.twig', self::context($products));
        self::assertStringNotContainsString('target-counter', $plain);

        // Premium (Gotenberg-class renderers, CPDF-P6-03/P6-05): TOC page numbers.
        $premium = $twig->render('catalog/grid.html.twig', self::context($products) + ['premium' => true]);
        self::assertStringContainsString('target-counter(attr(href url), page)', $premium);
    }
}
