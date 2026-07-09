<?php

declare(strict_types=1);

namespace App\Export\Contracts;

/**
 * Renders an HTML document to a PDF binary (ADR-0027, CPDF-P0-03).
 *
 * The port keeps the renderer swappable so a stock Cortex install needs no
 * extra infrastructure:
 *   - {@see \App\Export\Catalog\Infrastructure\Renderer\DompdfRenderer} —
 *     in-process, pure PHP, the default (zero infra);
 *   - `GotenbergRenderer` (CPDF-P6-03) — headless Chromium sidecar, opt-in
 *     behind `GOTENBERG_URL`, for high-fidelity / premium layouts;
 *   - PDFreactor — a future CMYK adapter (Faza 2).
 *
 * Callers (the catalog render service) depend only on this contract.
 */
interface PdfRenderer
{
    /**
     * @return string the rendered PDF as a binary string (starts with `%PDF-`)
     *
     * @throws PdfRenderException when the document cannot be rendered
     */
    public function render(string $html, PdfRenderOptions $options): string;
}
