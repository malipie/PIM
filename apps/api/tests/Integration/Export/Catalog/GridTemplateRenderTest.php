<?php

declare(strict_types=1);

namespace App\Tests\Integration\Export\Catalog;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * CPDF-P6-02 — renders the grid archetype through the real Twig environment
 * (strict_variables ON): the cover carries the brand tokens + catalog title,
 * the TOC links every product to an anchored card, the cards are chunked into
 * 3-per-row table rows, and the premium paged-media bits (target-counter TOC
 * page numbers) only appear when the `premium` flag is set — the graceful
 * Dompdf degradation demanded by the ticket.
 */
final class GridTemplateRenderTest extends KernelTestCase
{
    /**
     * @param list<array<string, string>> $products
     *
     * @return array{branding: array<string, string|null>, products: list<array<string, string>>, title: string}
     */
    private static function context(array $products): array
    {
        return [
            'branding' => [
                'color' => '#0ea5e9',
                'company_name' => 'ACME',
                'logo' => null,
            ],
            'products' => $products,
            'title' => 'Katalog wiosna',
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
    public function rendersCoverTocAndChunkedCardRows(): void
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

        // Cover: brand colour in <style>, company + catalog title + count.
        self::assertStringContainsString('#0ea5e9', $html);
        self::assertStringContainsString('Katalog wiosna', $html);
        self::assertStringContainsString('7 products', $html);
        // TOC: one anchor per product, pointing at the card ids.
        self::assertSame(7, substr_count($html, 'href="#product-'));
        self::assertSame(7, substr_count($html, 'id="product-'));
        self::assertStringContainsString('href="#product-7"', $html);
        self::assertStringContainsString('id="product-7"', $html);
        // Grid: 7 cards chunk into ceil(7/3) = 3 table rows.
        self::assertSame(3, substr_count($html, '<tr>'));
        // Page footer counter (CSS 2.1, Dompdf-supported).
        self::assertStringContainsString('counter(page)', $html);
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
