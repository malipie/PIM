<?php

declare(strict_types=1);

namespace App\DataFixtures\Demo;

use GdImage;
use RuntimeException;

/**
 * GD renderer for the electronics demo placeholder images: 800×600 JPEG
 * with a category-hued background, a white card, an accent circle and
 * the product text. Split out of {@see ElectronicsDemoSeeder} so the
 * seeder stays under the API max-lines guard.
 */
final class ElectronicsDemoImageFactory
{
    /**
     * Render the placeholder and return the temp-file path (caller unlinks).
     */
    public static function renderJpeg(string $sku, string $title, string $categoryLabel, int $hue): string
    {
        $im = imagecreatetruecolor(800, 600);
        if (false === $im) {
            throw new RuntimeException('imagecreatetruecolor failed.');
        }
        [$r, $g, $b] = self::hsvToRgb($hue, 0.35, 0.92);
        imagefilledrectangle($im, 0, 0, 800, 600, self::color($im, $r, $g, $b));

        [$r, $g, $b] = self::hsvToRgb($hue, 0.55, 0.72);
        imagefilledrectangle($im, 0, 0, 800, 90, self::color($im, $r, $g, $b));

        $white = self::color($im, 255, 255, 255);
        imagefilledrectangle($im, 60, 150, 740, 470, $white);

        [$r, $g, $b] = self::hsvToRgb($hue, 0.65, 0.55);
        imagefilledellipse($im, 400, 280, 170, 170, self::color($im, $r, $g, $b));

        $dark = self::color($im, 30, 34, 42);
        $ascii = static fn (string $text): string => (string) preg_replace('/[^\x20-\x7E]/', '', $text);
        imagestring($im, 5, 24, 36, $ascii($categoryLabel), $white);
        imagestring($im, 5, 80, 500, $ascii($title), $dark);
        imagestring($im, 4, 80, 530, $sku, $dark);

        $tmp = tempnam(sys_get_temp_dir(), 'pim-elec-');
        if (false === $tmp) {
            throw new RuntimeException('Failed to allocate a temp file for image generation.');
        }
        imagejpeg($im, $tmp, 82);
        imagedestroy($im);

        return $tmp;
    }

    private static function color(GdImage $im, int $r, int $g, int $b): int
    {
        $color = imagecolorallocate($im, max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
        if (false === $color) {
            throw new RuntimeException('imagecolorallocate failed.');
        }

        return $color;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function hsvToRgb(int $hue, float $saturation, float $value): array
    {
        $c = $value * $saturation;
        $x = $c * (1 - abs(fmod($hue / 60, 2) - 1));
        $m = $value - $c;
        [$r, $g, $b] = match (intdiv($hue % 360, 60)) {
            0 => [$c, $x, 0.0],
            1 => [$x, $c, 0.0],
            2 => [0.0, $c, $x],
            3 => [0.0, $x, $c],
            4 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };

        return [(int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255)];
    }
}
