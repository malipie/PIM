<?php

declare(strict_types=1);

namespace App\Export\Catalog\Application;

use RuntimeException;

/**
 * Raised when a catalog exceeds the product cap of an in-process renderer
 * (CPDF-P6-04, decision A): Dompdf builds the whole document in PHP memory,
 * so instead of letting a FrankenPHP worker die on OOM mid-render, the render
 * pipeline stops early with an actionable message. The async handler records
 * it on the CatalogRun (markError), so the operator sees it in the monitor.
 */
final class CatalogTooLargeException extends RuntimeException
{
    public static function forCap(int $cap): self
    {
        return new self(sprintf(
            'Catalog exceeds the %d-product limit of the in-process Dompdf renderer. '
            .'Narrow the catalog filter, or enable the Gotenberg sidecar for large catalogs '
            .'(CATALOG_PDF_RENDERER=gotenberg + GOTENBERG_URL, see docker compose profile "gotenberg").',
            $cap,
        ));
    }
}
