<?php

declare(strict_types=1);

namespace App\Asset\Contracts\Service;

/**
 * Resolves an asset reference to an inline `data:` URI so an offline renderer
 * — Dompdf runs with `isRemoteEnabled=false` (see {@see \App\Export\Catalog\Infrastructure\Renderer\DompdfRenderer})
 * — can embed the image without fetching an authenticated or remote URL.
 *
 * The catalog PDF pipeline feeds product image slots and the branding logo
 * through this seam (CPDF #2569/#2570): product images arrive as a bare asset
 * UUID (the export builder flattens the media envelope to its `asset_id`) and
 * the logo as a `/api/assets/{id}/preview` URL. Both must become inline bytes
 * before Dompdf renders them, otherwise the image is silently dropped.
 */
interface AssetInliner
{
    /**
     * @param string $reference a bare asset UUID or a `/api/assets/{id}/preview` URL
     *
     * @return string|null a `data:<mime>;base64,<payload>` URI, or null when the
     *                     reference is not a resolvable tenant asset
     */
    public function toDataUri(string $reference): ?string;
}
