<?php

declare(strict_types=1);

namespace App\Export\Catalog\Application\Delivery;

/**
 * Cache-and-serve storage seam for catalog PDFs (ADR-0027 §6.5, CPDF-P1-04).
 *
 * A generation streams the rendered PDF to a local temp file and hands it to
 * {@see put()} under a tenant-scoped key (`catalogs/<tenant>/<catalog>.pdf`);
 * the public URL endpoint (CPDF-P3-02) later streams the same key straight
 * back to the puller. Keeping this a narrow interface (rather than passing the
 * whole Flysystem operator around) keeps the generator testable with an
 * in-memory fake. Mirrors the Feed cache seam.
 */
interface CatalogCacheStorage
{
    /**
     * Store the local file's bytes at the given tenant-scoped key,
     * overwriting any previous cache for that catalog.
     */
    public function put(string $key, string $localPath): void;

    public function exists(string $key): bool;

    /**
     * Open a read stream on the cached object so the public URL (CPDF-P3-02)
     * streams it straight to the puller without buffering the whole PDF. The
     * caller owns closing the returned resource.
     *
     * @return resource
     */
    public function read(string $key);

    /**
     * Remove the cached object (e.g. when its catalog profile is deleted).
     * A missing key is a no-op.
     */
    public function delete(string $key): void;
}
