<?php

declare(strict_types=1);

namespace App\Export\Feed\Application\Delivery;

/**
 * Cache-and-serve storage seam for feeds (ADR-0023 §6.6, XMLF-P4-01/P3-05).
 *
 * A regeneration streams the generated XML to a local temp file and then
 * hands it to {@see put()} under a tenant-scoped key
 * (`feeds/<tenant>/<feed>.xml`); the public URL endpoint (XMLF-P3-05) later
 * streams the same key straight back to the puller. Keeping this a narrow
 * interface (rather than passing the whole Flysystem operator around) keeps
 * the generator testable with an in-memory fake.
 */
interface FeedCacheStorage
{
    /**
     * Store the local file's bytes at the given tenant-scoped key,
     * overwriting any previous cache for that feed.
     */
    public function put(string $key, string $localPath): void;

    public function exists(string $key): bool;

    /**
     * Open a read stream on the cached object so the public URL (XMLF-P3-05)
     * streams it straight to the puller without buffering the whole feed. The
     * caller owns closing the returned resource.
     *
     * @return resource
     */
    public function read(string $key);
}
