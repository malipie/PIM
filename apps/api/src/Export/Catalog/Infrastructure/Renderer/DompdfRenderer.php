<?php

declare(strict_types=1);

namespace App\Export\Catalog\Infrastructure\Renderer;

use App\Export\Contracts\PdfRenderer;
use App\Export\Contracts\PdfRenderException;
use App\Export\Contracts\PdfRenderOptions;
use Dompdf\Dompdf;
use Dompdf\Options;
use Throwable;

/**
 * In-process PDF renderer backed by dompdf/dompdf (ADR-0027, CPDF-P0-03).
 *
 * The default adapter: pure PHP, no sidecar, so a stock Harmon install renders
 * catalogs without any extra infrastructure. It is the only class in the repo
 * that touches {@see Dompdf}. Remote asset fetching stays off — the
 * catalog render service streams product images as data URIs upstream
 * (decision B). Note (decision A, CPDF-P6-04): Dompdf builds the whole document
 * in memory, so large catalogs are capped/chunked upstream.
 */
final class DompdfRenderer implements PdfRenderer
{
    public function supportsLargeDocuments(): bool
    {
        // Dompdf accumulates the whole document in PHP memory — the render
        // pipeline caps the product count (CPDF-P6-04, decision A).
        return false;
    }

    public function render(string $html, PdfRenderOptions $options): string
    {
        $dompdfOptions = new Options();
        $dompdfOptions->set('isRemoteEnabled', false);
        $dompdfOptions->set('isHtml5ParserEnabled', true);
        // #2608 — the templates embed data-URI @font-face fonts; Dompdf writes
        // their derived metrics into fontCache, which defaults to the vendored
        // lib dir. Point it (and tempDir) at the system temp dir so vendor/
        // stays read-only and the metrics survive per container lifetime.
        $fontCache = sys_get_temp_dir().'/pim-dompdf-cache';
        if (!is_dir($fontCache)) {
            @mkdir($fontCache, 0o775, true);
        }
        if (is_dir($fontCache) && is_writable($fontCache)) {
            $dompdfOptions->set('fontCache', $fontCache);
            $dompdfOptions->set('tempDir', $fontCache);
        }
        if ($options->basePath !== null) {
            $dompdfOptions->set('chroot', $options->basePath);
        }

        $dompdf = new Dompdf($dompdfOptions);
        $dompdf->setPaper($options->paperSize, $options->orientation);
        $dompdf->loadHtml($html);

        try {
            $dompdf->render();
            $output = $dompdf->output();
        } catch (Throwable $e) {
            throw new PdfRenderException('Dompdf failed to render the document.', 0, $e);
        }

        if (!str_starts_with($output, '%PDF-')) {
            throw new PdfRenderException('Dompdf produced an invalid PDF document.');
        }

        return $output;
    }
}
