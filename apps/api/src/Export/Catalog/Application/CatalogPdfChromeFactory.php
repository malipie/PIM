<?php

declare(strict_types=1);

namespace App\Export\Catalog\Application;

use App\Export\Catalog\Domain\Template\CatalogPdfPalette;
use App\Export\Catalog\Infrastructure\Template\CatalogPdfFontProvider;
use Psr\Clock\ClockInterface;

/**
 * Builds the shared "chrome" Twig context for the catalog PDF templates
 * (#2608): derived brand palette, embedded print fonts, localised static
 * labels and the generation date.
 *
 * Both {@see CatalogRenderService} and
 * {@see Preview\CatalogPreviewService} pass
 * this context to the same templates — one factory keeps the wizard preview
 * and the generated PDF pixel-identical (the two services deliberately mirror
 * each other; see the sync note on CatalogPreviewService).
 *
 * Static chrome strings (column headings, "Contents", the footer's page word,
 * the item-count line) follow the profile's locale: `pl*` renders Polish
 * chrome, anything else keeps the English chrome the templates always had.
 * Attribute VALUES are localised upstream by the export value pipeline — this
 * covers only the template-owned strings.
 */
final class CatalogPdfChromeFactory
{
    private const array LABELS = [
        'pl' => [
            'default_title' => 'Katalog produktów',
            'toc' => 'Spis treści',
            'page' => 'Strona',
            'sku' => 'SKU',
            'name' => 'Nazwa',
            'price' => 'Cena',
            'availability' => 'Dostępność',
            'specification' => 'Specyfikacja',
            'no_image' => 'brak zdjęcia',
            'generated' => 'Wygenerowano',
        ],
        'en' => [
            'default_title' => 'Product catalogue',
            'toc' => 'Contents',
            'page' => 'Page',
            'sku' => 'SKU',
            'name' => 'Name',
            'price' => 'Price',
            'availability' => 'Availability',
            'specification' => 'Specification',
            'no_image' => 'no image',
            'generated' => 'Generated',
        ],
    ];

    public function __construct(
        private readonly CatalogPdfFontProvider $fonts,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<mixed, mixed> $branding profile branding map (company_name?, logo?, color?)
     *
     * @return array{
     *     palette: array{accent: string, accent_dark: string, on_accent: string, on_accent_dark: string, tint_zebra: string, tint_panel: string},
     *     fonts: list<array{family: string, weight: int, style: string, src: string}>,
     *     labels: array<string, string>,
     *     generated_at: string,
     *     title: string,
     *     item_count: int,
     * }
     */
    public function chrome(array $branding, ?string $locale, int $itemCount, ?string $title): array
    {
        $lang = str_starts_with(strtolower($locale ?? ''), 'pl') ? 'pl' : 'en';
        $labels = self::LABELS[$lang];
        $labels['items'] = $this->itemsLine($lang, $itemCount);

        $color = $branding['color'] ?? null;
        $now = $this->clock->now();

        return [
            'palette' => CatalogPdfPalette::fromHex(\is_string($color) ? $color : null)->toArray(),
            'fonts' => $this->fonts->faces(),
            'labels' => $labels,
            'generated_at' => 'pl' === $lang ? $now->format('d.m.Y') : $now->format('M j, Y'),
            'title' => (null !== $title && '' !== trim($title)) ? $title : $labels['default_title'],
            'item_count' => $itemCount,
        ];
    }

    /**
     * "305 produktów" / "305 products" — with real Polish plural forms
     * (1 produkt, 3 produkty, 5 produktów, 22 produkty, 112 produktów).
     */
    private function itemsLine(string $lang, int $count): string
    {
        if ('pl' !== $lang) {
            return \sprintf('%d %s', $count, 1 === $count ? 'product' : 'products');
        }

        return \sprintf('%d %s', $count, $this->polishPlural($count, 'produkt', 'produkty', 'produktów'));
    }

    private function polishPlural(int $count, string $one, string $few, string $many): string
    {
        if (1 === $count) {
            return $one;
        }

        $mod10 = $count % 10;
        $mod100 = $count % 100;
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return $few;
        }

        return $many;
    }
}
