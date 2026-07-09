<?php

declare(strict_types=1);

namespace App\Export\Catalog\Infrastructure\Delivery;

use App\Export\Catalog\Application\Delivery\CatalogCacheStorage;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use RuntimeException;

/**
 * Flysystem-backed catalog PDF cache (CPDF-P1-04). Reuses the `exports.storage`
 * MinIO/S3 bucket the export feature already provisions, under a dedicated
 * `catalogs/` prefix so a lifecycle/TTL policy can target catalogs
 * independently. Tenant isolation rides on the key prefix
 * (`catalogs/<tenant>/…`), the same application-layer barrier exports and feeds
 * use (RLS does not extend to object storage in MVP). Mirrors
 * {@see \App\Export\Feed\Infrastructure\Delivery\FlysystemFeedCacheStorage}.
 */
final class FlysystemCatalogCacheStorage implements CatalogCacheStorage
{
    public function __construct(
        private readonly FilesystemOperator $exportsStorage,
    ) {
    }

    public function put(string $key, string $localPath): void
    {
        $stream = @fopen($localPath, 'r');
        if (false === $stream) {
            throw new RuntimeException(sprintf('Unable to read local catalog file "%s" for cache upload.', $localPath));
        }
        try {
            $this->exportsStorage->writeStream($key, $stream);
        } catch (FilesystemException $error) {
            throw new RuntimeException(sprintf('Catalog cache upload failed for "%s": %s', $key, $error->getMessage()), previous: $error);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function exists(string $key): bool
    {
        try {
            return $this->exportsStorage->fileExists($key);
        } catch (FilesystemException) {
            return false;
        }
    }

    public function read(string $key)
    {
        try {
            return $this->exportsStorage->readStream($key);
        } catch (FilesystemException $error) {
            throw new RuntimeException(sprintf('Catalog cache read failed for "%s": %s', $key, $error->getMessage()), previous: $error);
        }
    }

    public function delete(string $key): void
    {
        try {
            $this->exportsStorage->delete($key);
        } catch (FilesystemException $error) {
            throw new RuntimeException(sprintf('Catalog cache delete failed for "%s": %s', $key, $error->getMessage()), previous: $error);
        }
    }
}
