<?php

declare(strict_types=1);

namespace App\Tests\Integration\Export\Catalog;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * CPDF-P6-01 — renders the pricelist archetype through the real Twig
 * environment (strict_variables ON) to prove the template is valid, brand
 * tokens land in the <style> block, and the Dompdf paged-media hooks are in
 * place: a `<thead>` repeated via table-header-group and a fixed page footer
 * numbered with counter(page). Mirrors {@see SheetTemplateRenderTest}.
 */
final class PricelistTemplateRenderTest extends KernelTestCase
{
    #[Test]
    public function rendersPricelistWithBrandColourAndPagedMediaHooks(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->render('catalog/pricelist.html.twig', [
            'branding' => [
                'color' => '#0ea5e9',
                'company_name' => 'ACME',
                'logo' => null,
            ],
            'products' => [
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
            ],
        ]);

        // Brand colour injected verbatim into the <style> block (not via CSS var).
        self::assertStringContainsString('#0ea5e9', $html);
        self::assertStringContainsString('Wiertarka X1', $html);
        self::assertStringContainsString('349,00 zł', $html);
        // The paged-media hooks Dompdf repeats on every page.
        self::assertStringContainsString('table-header-group', $html);
        self::assertStringContainsString('counter(page)', $html);
        self::assertStringContainsString('<thead>', $html);
    }
}
