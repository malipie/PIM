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
 * CPDF-P2-01 / #2608 — renders the sheet archetype through the real Twig
 * environment (strict_variables ON) with the shared chrome context: brand
 * palette in the <style> block, embedded @font-face fonts, the framed cover,
 * localised labels and the styled no-image placeholder.
 */
final class SheetTemplateRenderTest extends KernelTestCase
{
    #[Test]
    public function rendersSheetWithChromeCoverAndSpecs(): void
    {
        $html = $this->render('pl');

        // Palette accent (derived from branding.color) lands verbatim in <style>.
        self::assertStringContainsString('#0ea5e9', $html);
        // Embedded print fonts — both families, as data: URIs.
        self::assertStringContainsString('@font-face', $html);
        self::assertStringContainsString("font-family: 'Fraunces'", $html);
        self::assertStringContainsString("font-family: 'Inter'", $html);
        self::assertStringContainsString('data:font/truetype;base64,', $html);
        // Cover: catalog title + localised item count + generation date.
        self::assertStringContainsString('Katalog narzędzi', $html);
        self::assertStringContainsString('2 produkty', $html);
        self::assertStringContainsString('17.07.2026', $html);
        // Product sheet content.
        self::assertStringContainsString('Wiertarka X1', $html);
        self::assertStringContainsString('Waga', $html);
        // Polish chrome labels.
        self::assertStringContainsString('Specyfikacja', $html);
        self::assertStringContainsString('Strona', $html);
    }

    #[Test]
    public function missingImageRendersTheLocalisedPlaceholder(): void
    {
        $html = $this->render('pl');

        // The second product has no image — the tinted panel carries the
        // localised placeholder instead of a broken <img>.
        self::assertStringContainsString('brak zdjęcia', $html);
        self::assertSame(1, substr_count($html, '<img'), 'only the first product has a real image');
    }

    #[Test]
    public function englishChromeIsTheDefault(): void
    {
        $html = $this->render(null);

        self::assertStringContainsString('2 products', $html);
        self::assertStringContainsString('Specification', $html);
        self::assertStringContainsString('no image', $html);
    }

    private function render(?string $locale): string
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
                'title' => 'Wiertarka X1',
                'sku' => 'SKU-001',
                'image' => 'data:image/png;base64,iVBORw0KGgo=',
                'description' => '<strong>Opis</strong>',
                'price' => '199,00 zł',
                'specs' => [
                    ['label' => 'Waga', 'value' => '2kg'],
                ],
            ],
            [
                'title' => 'Szlifierka Z4',
                'sku' => 'SKU-002',
                'image' => '',
                'description' => '',
                'price' => '349,00 zł',
                'specs' => [],
            ],
        ];

        return $twig->render('catalog/sheet.html.twig', [
            'branding' => $branding,
            'products' => $products,
            ...self::chrome()->chrome($branding, $locale, \count($products), 'Katalog narzędzi'),
        ]);
    }

    private static function chrome(): CatalogPdfChromeFactory
    {
        return new CatalogPdfChromeFactory(
            new CatalogPdfFontProvider(\dirname(__DIR__, 4).'/assets/pdf-fonts'),
            new MockClock('2026-07-17T12:00:00+00:00'),
        );
    }
}
