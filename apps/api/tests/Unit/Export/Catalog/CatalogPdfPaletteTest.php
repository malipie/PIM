<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Catalog;

use App\Export\Catalog\Domain\Template\CatalogPdfPalette;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #2608 — the print palette derived from the profile's branding colour: the
 * accent stays verbatim, large surfaces get a darkened variant, text colours
 * follow WCAG contrast, and zebra/panel tints stay near-white.
 */
final class CatalogPdfPaletteTest extends TestCase
{
    #[Test]
    public function derivesTheFullSystemFromABrandRed(): void
    {
        $palette = CatalogPdfPalette::fromHex('#ff0000');

        self::assertSame('#ff0000', $palette->accent);
        self::assertSame('#ad0000', $palette->accentDark);
        // Pure red / dark red both take white text.
        self::assertSame('#ffffff', $palette->onAccent);
        self::assertSame('#ffffff', $palette->onAccentDark);
        // Tints are near-white shades of the accent (red channel dominates).
        self::assertSame('#ffeded', $palette->tintZebra);
        self::assertSame('#fff4f4', $palette->tintPanel);
    }

    #[Test]
    public function lightAccentsTakeInkTextInsteadOfWhite(): void
    {
        $palette = CatalogPdfPalette::fromHex('#ffee00');

        self::assertSame('#16181d', $palette->onAccent, 'Yellow cannot carry white text.');
    }

    #[Test]
    public function shortHexNotationExpands(): void
    {
        self::assertSame('#ff0000', CatalogPdfPalette::fromHex('#f00')->accent);
    }

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function garbageInputs(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'not a colour' => ['tomato'];
        yield 'truncated hex' => ['#ff00'];
        yield 'non-hex digits' => ['#zzzzzz'];
    }

    #[Test]
    #[DataProvider('garbageInputs')]
    public function garbageFallsBackToTheDefaultAccent(?string $input): void
    {
        self::assertSame('#1d4ed8', CatalogPdfPalette::fromHex($input)->accent);
    }

    #[Test]
    public function toArrayUsesTwigFriendlySnakeCaseKeys(): void
    {
        self::assertSame(
            ['accent', 'accent_dark', 'on_accent', 'on_accent_dark', 'tint_zebra', 'tint_panel'],
            array_keys(CatalogPdfPalette::fromHex('#336699')->toArray()),
        );
    }
}
