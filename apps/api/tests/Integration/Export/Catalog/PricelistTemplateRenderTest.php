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
 * CPDF-P6-01 / #2608 — renders the pricelist archetype through the real Twig
 * environment (strict_variables ON) with the shared chrome context: brand
 * palette + fonts in <style>, the framed cover, localised column headings,
 * and the Dompdf paged-media hooks (repeating `<thead>` via
 * table-header-group, fixed footer numbered with counter(page)).
 */
final class PricelistTemplateRenderTest extends KernelTestCase
{
    #[Test]
    public function rendersPricelistWithChromeAndPagedMediaHooks(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $branding = [
            'color' => '#0ea5e9',
            'company_name' => 'ACME',
            'logo' => null,
        ];
        $products = [
            [
                'sku' => 'SKU-001',
                'name' => 'Wiertarka X1',
                'price' => '199,00 zł',
                'availability' => 'in stock',
            ],
            [
                'sku' => 'SKU-002',
                'name' => 'Szlifierka Z4',
                'price' => '349,00 zł',
                'availability' => 'out of stock',
            ],
        ];

        $chrome = new CatalogPdfChromeFactory(
            new CatalogPdfFontProvider(\dirname(__DIR__, 4).'/assets/pdf-fonts'),
            new MockClock('2026-07-17T12:00:00+00:00'),
        );
        $html = $twig->render('catalog/pricelist.html.twig', [
            'branding' => $branding,
            'products' => $products,
            ...$chrome->chrome($branding, 'pl', \count($products), 'Cennik hurtowy'),
        ]);

        // Palette accent injected verbatim into the <style> block (not via CSS var).
        self::assertStringContainsString('#0ea5e9', $html);
        self::assertStringContainsString('@font-face', $html);
        // Cover + title block.
        self::assertStringContainsString('Cennik hurtowy', $html);
        self::assertStringContainsString('2 produkty', $html);
        // Localised column headings.
        self::assertStringContainsString('Nazwa', $html);
        self::assertStringContainsString('Cena', $html);
        self::assertStringContainsString('Dostępność', $html);
        // Rows.
        self::assertStringContainsString('Wiertarka X1', $html);
        self::assertStringContainsString('349,00 zł', $html);
        // The paged-media hooks Dompdf repeats on every page.
        self::assertStringContainsString('table-header-group', $html);
        self::assertStringContainsString('counter(page)', $html);
        self::assertStringContainsString('<thead>', $html);
    }
}
