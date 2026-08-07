<?php

declare(strict_types=1);

namespace App\Export\Contracts;

/**
 * Renders an HTML document to a PDF binary (ADR-0027, CPDF-P0-03).
 *
 * The port keeps the renderer swappable so a stock Harmon install needs no
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

    /**
     * Whether the renderer can take arbitrarily large documents (CPDF-P6-04,
     * decision A). In-process engines that build the whole document in PHP
     * memory (Dompdf) answer false and the catalog render pipeline enforces a
     * product cap on them; sidecar engines with their own process space
     * (Gotenberg) answer true and render uncapped.
     */
    public function supportsLargeDocuments(): bool;
}
