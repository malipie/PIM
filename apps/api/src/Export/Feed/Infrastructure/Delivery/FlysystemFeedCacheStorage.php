<?php

declare(strict_types=1);

namespace App\Export\Feed\Infrastructure\Delivery;

use App\Export\Feed\Application\Delivery\FeedCacheStorage;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use RuntimeException;

/**
 * Flysystem-backed feed cache (XMLF-P4-01). Reuses the `exports.storage`
 * MinIO/S3 bucket the export feature already provisions, under a dedicated
 * `feeds/` prefix so a lifecycle/TTL policy can target feeds independently.
 * Tenant isolation rides on the key prefix (`feeds/<tenant>/…`), the same
 * application-layer barrier exports use (RLS does not extend to object
 * storage in MVP).
 */
final class FlysystemFeedCacheStorage implements FeedCacheStorage
{
    public function __construct(
        private readonly FilesystemOperator $exportsStorage,
    ) {
    }

    public function put(string $key, string $localPath): void
    {
        $stream = @fopen($localPath, 'r');
        if (false === $stream) {
            throw new RuntimeException(sprintf('Unable to read local feed file "%s" for cache upload.', $localPath));
        }
        try {
            $this->exportsStorage->writeStream($key, $stream);
        } catch (FilesystemException $error) {
            throw new RuntimeException(sprintf('Feed cache upload failed for "%s": %s', $key, $error->getMessage()), previous: $error);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
