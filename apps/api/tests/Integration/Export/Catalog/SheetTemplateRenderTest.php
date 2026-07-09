<?php

declare(strict_types=1);

namespace App\Tests\Integration\Export\Catalog;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * CPDF-P2-01 — renders the sheet archetype through the real Twig environment
 * (strict_variables ON) to prove the template is valid and that brand tokens
 * are injected into the <style> block. Deliberately does NOT touch the Dompdf
 * PdfRenderer — that lands in CPDF-P2-03.
 */
final class SheetTemplateRenderTest extends KernelTestCase
{
    #[Test]
    public function rendersSheetWithBrandColourAndSpecs(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->render('catalog/sheet.html.twig', [
            'branding' => [
                'color' => '#0ea5e9',
                'company_name' => 'ACME',
                'logo' => null,
            ],
            'products' => [
                [
                    'title' => 'Wiertarka X1',
                    'sku' => 'SKU-001',
                    'image' => null,
                    'description' => '<strong>Opis</strong>',
                    'price' => '199,00 zł',
                    'specs' => [
                        ['label' => 'Waga', 'value' => '2kg'],
                    ],
                ],
            ],
        ]);

        // Brand colour injected verbatim into the <style> block (not via CSS var).
        self::assertStringContainsString('#0ea5e9', $html);
        self::assertStringContainsString('Wiertarka X1', $html);
        self::assertStringContainsString('Waga', $html);
    }
}
