<?php

declare(strict_types=1);

namespace App\Export\Catalog\Domain\Template;

/**
 * Print palette derived from the profile's single branding colour (#2608).
 *
 * The catalog templates never flood pages with the raw brand colour — they use
 * a small system derived from it: the accent itself (chips, rules), a darkened
 * variant for large surfaces (cover panel) and big type, WCAG-aware text
 * colours for both, and two near-white tints for zebra rows and image panels.
 * Deriving the system here keeps the Twig side free of colour math (Dompdf has
 * no CSS colour functions) and makes any operator-picked colour — including
 * #ff0000 — look designed instead of shouted.
 */
final class CatalogPdfPalette
{
    /** Default when the profile has no (valid) branding colour — the pre-redesign template default. */
    private const string DEFAULT_ACCENT = '#1d4ed8';

    /** Near-black ink used for text on light backgrounds. */
    private const string INK = '#16181d';

    private const float DARKEN_FACTOR = 0.68;
    private const float ZEBRA_TINT_RATIO = 0.93;
    private const float PANEL_TINT_RATIO = 0.955;

    private function __construct(
        public readonly string $accent,
        public readonly string $accentDark,
        public readonly string $onAccent,
        public readonly string $onAccentDark,
        public readonly string $tintZebra,
        public readonly string $tintPanel,
    ) {
    }

    public static function fromHex(?string $hex): self
    {
        $rgb = self::parse($hex) ?? self::parse(self::DEFAULT_ACCENT);
        \assert(null !== $rgb);

        $dark = self::scale($rgb, self::DARKEN_FACTOR);

        return new self(
            accent: self::toHex($rgb),
            accentDark: self::toHex($dark),
            onAccent: self::textOn($rgb),
            onAccentDark: self::textOn($dark),
            tintZebra: self::toHex(self::mixTowardWhite($rgb, self::ZEBRA_TINT_RATIO)),
            tintPanel: self::toHex(self::mixTowardWhite($rgb, self::PANEL_TINT_RATIO)),
        );
    }

    /**
     * Twig-friendly shape (snake_case keys, plain strings).
     *
     * @return array{accent: string, accent_dark: string, on_accent: string, on_accent_dark: string, tint_zebra: string, tint_panel: string}
     */
    public function toArray(): array
    {
        return [
            'accent' => $this->accent,
            'accent_dark' => $this->accentDark,
            'on_accent' => $this->onAccent,
            'on_accent_dark' => $this->onAccentDark,
            'tint_zebra' => $this->tintZebra,
            'tint_panel' => $this->tintPanel,
        ];
    }

    /**
     * @return array{int, int, int}|null
     */
    private static function parse(?string $hex): ?array
    {
        if (null === $hex) {
            return null;
        }

        $hex = ltrim(trim($hex), '#');
        if (1 === preg_match('/^[0-9a-fA-F]{3}$/', $hex)) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (1 !== preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return null;
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * @param array{int, int, int} $rgb
     *
     * @return array{int, int, int}
     */
    private static function scale(array $rgb, float $factor): array
    {
        return [
            (int) round($rgb[0] * $factor),
            (int) round($rgb[1] * $factor),
            (int) round($rgb[2] * $factor),
        ];
    }

    /**
     * @param array{int, int, int} $rgb
     *
     * @return array{int, int, int}
     */
    private static function mixTowardWhite(array $rgb, float $ratio): array
    {
        return [
            (int) round($rgb[0] + (255 - $rgb[0]) * $ratio),
            (int) round($rgb[1] + (255 - $rgb[1]) * $ratio),
            (int) round($rgb[2] + (255 - $rgb[2]) * $ratio),
        ];
    }

    /**
     * White when it clears WCAG AA for large text (>= 3:1) against $rgb, ink
     * otherwise. White-first on purpose: on saturated mid-tones (pure red) a
     * strict highest-contrast pick would choose ink by a hair, but reversed
     * white type is the print convention and stays comfortably legible.
     *
     * @param array{int, int, int} $rgb
     */
    private static function textOn(array $rgb): string
    {
        $whiteContrast = 1.05 / (self::luminance($rgb) + 0.05);

        return $whiteContrast >= 3.0 ? '#ffffff' : self::INK;
    }

    /**
     * WCAG 2.x relative luminance.
     *
     * @param array{int, int, int} $rgb
     */
    private static function luminance(array $rgb): float
    {
        $channel = static function (int $value): float {
            $c = $value / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($rgb[0]) + 0.7152 * $channel($rgb[1]) + 0.0722 * $channel($rgb[2]);
    }

    /**
     * @param array{int, int, int} $rgb
     */
    private static function toHex(array $rgb): string
    {
        return \sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
    }
}
