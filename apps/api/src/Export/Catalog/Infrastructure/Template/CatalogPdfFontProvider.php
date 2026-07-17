<?php

declare(strict_types=1);

namespace App\Export\Catalog\Infrastructure\Template;

/**
 * Supplies the catalog print fonts as `data:` URI @font-face sources (#2608).
 *
 * Both renderers consume the same self-contained HTML: Dompdf runs with
 * `isRemoteEnabled=false` and Gotenberg receives a single `index.html` with no
 * side files, so a file path or URL would not resolve in either — inline
 * `data:` URIs are the one src both engines load (validated in-container:
 * Dompdf 3.1 registers, uses and SUBSETS data-URI TrueType faces, so the
 * embedded-font cost in the output PDF is a few kilobytes).
 *
 * The faces are static latin + latin-ext TTF subsets (full Polish diacritics)
 * of Fraunces and Inter, vendored under assets/pdf-fonts with their OFL
 * licences (neither family declares a Reserved Font Name, so redistributing
 * subsets under the original names is licence-clean). A missing file degrades
 * to the templates' DejaVu fallback stack instead of failing the render.
 *
 * Resolutions are memoised per instance — one worker process pays the disk
 * read + base64 once, not once per catalog run (FrankenPHP worker mode).
 */
final class CatalogPdfFontProvider
{
    /** filename => [css font-family, font-weight, font-style] */
    private const array FACES = [
        'Fraunces-Regular.ttf' => ['Fraunces', 400, 'normal'],
        'Fraunces-Italic.ttf' => ['Fraunces', 400, 'italic'],
        'Fraunces-SemiBold.ttf' => ['Fraunces', 600, 'normal'],
        'Inter-Regular.ttf' => ['Inter', 400, 'normal'],
        'Inter-SemiBold.ttf' => ['Inter', 600, 'normal'],
        'Inter-Bold.ttf' => ['Inter', 700, 'normal'],
    ];

    /** @var list<array{family: string, weight: int, style: string, src: string}>|null */
    private ?array $faces = null;

    public function __construct(private readonly string $fontDir)
    {
    }

    /**
     * @return list<array{family: string, weight: int, style: string, src: string}>
     */
    public function faces(): array
    {
        if (null !== $this->faces) {
            return $this->faces;
        }

        $faces = [];
        foreach (self::FACES as $file => [$family, $weight, $style]) {
            $path = $this->fontDir.'/'.$file;
            if (!is_file($path)) {
                continue;
            }
            $bytes = file_get_contents($path);
            if (false === $bytes || '' === $bytes) {
                continue;
            }
            $faces[] = [
                'family' => $family,
                'weight' => $weight,
                'style' => $style,
                'src' => 'data:font/truetype;base64,'.base64_encode($bytes),
            ];
        }

        return $this->faces = $faces;
    }
}
