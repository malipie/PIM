<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Catalog;

use App\Export\Catalog\Infrastructure\Template\CatalogPdfFontProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #2608 — the vendored print fonts (Fraunces + Inter, latin + latin-ext TTF
 * subsets) resolve to data: URI faces both PDF engines can load.
 */
final class CatalogPdfFontProviderTest extends TestCase
{
    #[Test]
    public function exposesAllSixVendoredFacesAsDataUris(): void
    {
        $faces = new CatalogPdfFontProvider(self::fontDir())->faces();

        self::assertCount(6, $faces);

        $families = array_unique(array_column($faces, 'family'));
        sort($families);
        self::assertSame(['Fraunces', 'Inter'], $families);

        foreach ($faces as $face) {
            self::assertStringStartsWith('data:font/truetype;base64,', $face['src']);
            self::assertGreaterThan(10_000, \strlen($face['src']), 'A real TTF payload, not a stub.');
        }

        // The weights the templates rely on.
        $interWeights = array_column(array_filter($faces, static fn (array $f): bool => 'Inter' === $f['family']), 'weight');
        sort($interWeights);
        self::assertSame([400, 600, 700], $interWeights);
    }

    #[Test]
    public function missingDirectoryDegradesToAnEmptyListNotAnError(): void
    {
        self::assertSame([], new CatalogPdfFontProvider('/nonexistent-font-dir')->faces());
    }

    private static function fontDir(): string
    {
        return \dirname(__DIR__, 4).'/assets/pdf-fonts';
    }
}
