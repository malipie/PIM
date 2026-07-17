<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Catalog;

use App\Export\Catalog\Application\CatalogPdfChromeFactory;
use App\Export\Catalog\Infrastructure\Template\CatalogPdfFontProvider;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * #2608 — the shared "chrome" context (palette, fonts, localised labels,
 * generation date) both the PDF render and the wizard preview feed to the
 * catalog templates.
 */
final class CatalogPdfChromeFactoryTest extends TestCase
{
    #[Test]
    public function polishLocaleGetsPolishChrome(): void
    {
        $chrome = $this->factory()->chrome(['color' => '#ff0000'], 'pl_PL', 305, 'Katalog obuwniczy');

        self::assertSame('#ff0000', $chrome['palette']['accent']);
        self::assertSame('Spis treści', $chrome['labels']['toc']);
        self::assertSame('305 produktów', $chrome['labels']['items']);
        self::assertSame('17.07.2026', $chrome['generated_at']);
        self::assertSame('Katalog obuwniczy', $chrome['title']);
        self::assertSame(305, $chrome['item_count']);
    }

    #[Test]
    public function nonPolishLocaleKeepsEnglishChrome(): void
    {
        $chrome = $this->factory()->chrome([], null, 2, null);

        self::assertSame('Contents', $chrome['labels']['toc']);
        self::assertSame('2 products', $chrome['labels']['items']);
        self::assertSame('Jul 17, 2026', $chrome['generated_at']);
        // No title given — the localised fallback steps in.
        self::assertSame('Product catalogue', $chrome['title']);
        // No branding colour — palette falls back to the default accent.
        self::assertSame('#1d4ed8', $chrome['palette']['accent']);
    }

    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function polishPlurals(): iterable
    {
        yield 'one' => [1, '1 produkt'];
        yield 'few' => [3, '3 produkty'];
        yield 'many' => [5, '5 produktów'];
        yield 'teens stay many' => [12, '12 produktów'];
        yield 'few again past the teens' => [22, '22 produkty'];
        yield 'hundreds' => [305, '305 produktów'];
    }

    #[Test]
    #[DataProvider('polishPlurals')]
    public function polishItemCountsUseRealPluralForms(int $count, string $expected): void
    {
        $chrome = $this->factory()->chrome([], 'pl', $count, 'X');

        self::assertSame($expected, $chrome['labels']['items']);
    }

    #[Test]
    public function fontsComeFromTheProvider(): void
    {
        $chrome = $this->factory()->chrome([], null, 1, 'X');

        self::assertIsArray($chrome['fonts']);
        self::assertCount(6, $chrome['fonts']);
    }

    private function factory(): CatalogPdfChromeFactory
    {
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-07-17T12:00:00+00:00');
            }
        };

        return new CatalogPdfChromeFactory(
            new CatalogPdfFontProvider(\dirname(__DIR__, 4).'/assets/pdf-fonts'),
            $clock,
        );
    }
}
