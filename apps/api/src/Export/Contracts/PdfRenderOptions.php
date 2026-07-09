<?php

declare(strict_types=1);

namespace App\Export\Contracts;

/**
 * Renderer-agnostic options passed to a {@see PdfRenderer} (ADR-0027, CPDF-P0-03).
 *
 * Primitive by design so both the in-process Dompdf adapter and the future
 * Gotenberg sidecar (CPDF-P6-03) read the same shape. `basePath` is the local
 * root a renderer may resolve relative asset paths against.
 */
final class PdfRenderOptions
{
    public function __construct(
        public readonly string $paperSize = 'A4',
        public readonly string $orientation = 'portrait',
        public readonly ?string $basePath = null,
    ) {
    }
}
