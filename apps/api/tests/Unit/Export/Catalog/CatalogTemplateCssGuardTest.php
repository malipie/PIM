<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CPDF-P6-05 — the "safe CSS subset" guard for the catalog archetypes (R2):
 * every template renders on BOTH engines (Dompdf CSS 2.1 + Gotenberg/Chromium),
 * so the Dompdf path must never grow constructs Dompdf silently mangles:
 *
 *   - `display: flex` / `display: grid` (Dompdf has no layout for either),
 *   - CSS custom properties (`var(--x)` is not resolved reliably — brand
 *     tokens are interpolated by Twig straight into <style>),
 *   - `position: sticky` (not part of CSS 2.1),
 *   - CSS Paged Media beyond the basics — `target-counter()` / `running()` /
 *     `@page` margin-box at-rules render ONLY on Gotenberg-class engines and
 *     must sit inside a `{% if premium %}` Twig guard so the Dompdf render
 *     degrades gracefully (grid TOC page numbers are the canonical case).
 *
 * A new archetype that violates the subset fails here, not in a customer's
 * mangled PDF.
 */
final class CatalogTemplateCssGuardTest extends TestCase
{
    /**
     * Every archetype plus the shared `_chrome` partials (#2608) — the chrome
     * CSS renders on the Dompdf path of all three archetypes, so it must obey
     * the same subset.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function templates(): iterable
    {
        $paths = array_merge(
            self::globTemplates(self::templatesDir().'/*.html.twig'),
            self::globTemplates(self::templatesDir().'/_chrome/*.html.twig'),
        );
        foreach ($paths as $path) {
            yield basename($path) => [$path];
        }
    }

    #[Test]
    public function everyArchetypeIsCovered(): void
    {
        $found = array_map(basename(...), self::globTemplates(self::templatesDir().'/*.html.twig'));
        sort($found);

        self::assertSame(['grid.html.twig', 'pricelist.html.twig', 'sheet.html.twig'], $found);
    }

    /**
     * @return list<string>
     */
    private static function globTemplates(string $pattern): array
    {
        $paths = glob($pattern);

        return false === $paths ? [] : $paths;
    }

    #[Test]
    #[DataProvider('templates')]
    public function staysInsideTheDompdfSafeSubset(string $path): void
    {
        $source = self::withoutTwigComments((string) file_get_contents($path));
        $name = basename($path);

        self::assertDoesNotMatchRegularExpression(
            '/display:\s*(flex|grid)\b/',
            $source,
            sprintf('%s uses a layout mode Dompdf cannot render — use tables/floats on the Dompdf path.', $name),
        );
        self::assertStringNotContainsString(
            'var(--',
            $source,
            sprintf('%s uses CSS custom properties — interpolate brand tokens via Twig instead.', $name),
        );
        self::assertDoesNotMatchRegularExpression(
            '/position:\s*sticky/',
            $source,
            sprintf('%s uses position:sticky, which is outside the CSS 2.1 subset.', $name),
        );
    }

    #[Test]
    #[DataProvider('templates')]
    public function premiumPagedMediaOnlyInsideThePremiumGuard(string $path): void
    {
        $source = self::withoutTwigComments((string) file_get_contents($path));
        $name = basename($path);

        // Strip `{% if premium... %} ... {% endif %}` blocks, then no premium
        // paged-media construct may remain on the always-rendered (Dompdf) path.
        $unguarded = preg_replace('/\{%\s*if\s+premium.*?\{%\s*endif\s*%\}/s', '', $source) ?? $source;

        foreach (['target-counter', 'running(', '@top-', '@bottom-'] as $construct) {
            self::assertStringNotContainsString(
                $construct,
                $unguarded,
                sprintf(
                    '%s uses "%s" outside a {%% if premium %%} guard — Dompdf cannot render it; gate it to Gotenberg.',
                    $name,
                    $construct,
                ),
            );
        }
    }

    private static function templatesDir(): string
    {
        return \dirname(__DIR__, 4).'/templates/catalog';
    }

    /**
     * The guard scans CODE, not documentation — the header comments legally
     * mention the banned constructs when explaining why they are banned.
     */
    private static function withoutTwigComments(string $source): string
    {
        return preg_replace('/\{#.*?#\}/s', '', $source) ?? $source;
    }
}
