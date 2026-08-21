<?php

declare(strict_types=1);

namespace App\Asset\Application;

use App\Asset\Application\Exception\DuplicateAssetException;
use App\Asset\Contracts\Event\AssetThumbnailsRequested;
use App\Asset\Domain\Entity\Asset;
use App\Asset\Domain\Entity\AssetVariant;
use App\Asset\Domain\Repository\AssetRepositoryInterface;
use App\Catalog\Contracts\Service\CatalogAssetSync;
use App\Shared\Application\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

use const PATHINFO_EXTENSION;
use const PATHINFO_FILENAME;

/**
 * Uploads a binary file to the assets storage and creates the matching
 * `Asset` + `original` `AssetVariant` rows.
 *
 * Storage layout: `<tenant-uuid>/<asset-uuid>/original.<ext>` — tenant
 * UUID prefix gives a coarse path-level isolation that mirrors the
 * Doctrine TenantFilter on the database side.
 *
 * The pipeline (#438):
 *   1. SHA-256 the source bytes streaming-style (constant memory).
 *   2. Look the hash up against the tenant — bail with
 *      {@see DuplicateAssetException} if a row already exists, leaving
 *      the bucket untouched.
 *   3. Stream the bytes into the bucket, persist Asset + original
 *      variant.
 *   4. Capture image dimensions (`getimagesize` for raster formats)
 *      synchronously; page count + thumbnail variants are produced by
 *      the async {@see AssetThumbnailHandler}.
 *   5. Dispatch {@see AssetThumbnailsRequested} on the
 *      `assets-thumbnails` async transport.
 *
 * The smoke-test CLI (`pim:asset:upload`) calls this same path, so any
 * regression manifests in both surfaces.
 *
 * #2944 — a browser upload arrives as an {@see UploadedFile}, whose
 * `getFilename()`/`getExtension()` describe PHP's *temporary* file, not the
 * operator's. Reading those stored `phpA1b2C3` as the original name and wrote
 * every object to `original.bin`; imported assets looked fine because
 * {@see AssetIngestor} passes a real name. The client name is display-only
 * and never reaches the storage path — see `storageExtension()`.
 */
final readonly class AssetUploader
{
    public function __construct(
        private FilesystemOperator $assetsStorage,
        private EntityManagerInterface $em,
        private TenantContext $tenantContext,
        private AssetRepositoryInterface $assets,
        private MessageBusInterface $bus,
        private SluggerInterface $slugger,
        private CatalogAssetSync $catalogAssetSync,
        private \Psr\Log\LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<int, string> $tags
     */
    public function upload(File $file, ?string $code = null, array $tags = [], ?string $folderCode = null): Asset
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new RuntimeException('AssetUploader requires an active TenantContext.');
        }

        $sourcePath = $file->getPathname();
        $contentHash = hash_file('sha256', $sourcePath);
        if (false === $contentHash) {
            throw new RuntimeException(\sprintf('Failed to hash uploaded file %s.', $sourcePath));
        }

        $existing = $this->assets->findByContentHash($contentHash, $tenant);
        if (null !== $existing) {
            throw new DuplicateAssetException($existing->getId(), $existing->getCode());
        }

        $originalFilename = self::clientFilename($file);
        $resolvedCode = $this->resolveCode($code, $originalFilename, $tenant);

        $assetId = Uuid::v7();
        $extension = self::storageExtension($file, $originalFilename);
        $storagePath = \sprintf(
            '%s/%s/original.%s',
            $tenant->getId()->toRfc4122(),
            $assetId->toRfc4122(),
            $extension,
        );

        $stream = fopen($sourcePath, 'r');
        if (false === $stream) {
            throw new RuntimeException(\sprintf('Failed to open uploaded file %s for reading.', $sourcePath));
        }
        try {
            $this->assetsStorage->writeStream($storagePath, $stream);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        $size = $file->getSize();
        if (false === $size) {
            $size = 0;
        }

        $mimeType = $file->getMimeType() ?? 'application/octet-stream';
        [$width, $height] = $this->probeDimensions($sourcePath, $mimeType);

        $asset = new Asset(
            code: $resolvedCode,
            originalFilename: $originalFilename,
            mimeType: $mimeType,
            size: $size,
            storagePath: $storagePath,
            id: $assetId,
            contentHash: $contentHash,
            width: $width,
            height: $height,
            tags: $tags,
            folderCode: $folderCode,
        );

        $original = new AssetVariant(
            asset: $asset,
            variantCode: AssetVariant::CODE_ORIGINAL,
            storagePath: $storagePath,
            mimeType: $asset->getMimeType(),
            size: $size,
        );
        $asset->addVariant($original);

        $this->em->persist($asset);
        $this->em->persist($original);
        $this->em->flush();

        // Mirror the new Asset into a CatalogObject(kind=asset) so the
        // `/api/assets` grid (which lists CatalogObject rows) shows the
        // upload. Storage-side fields go straight into
        // `attributes_indexed` (denormalised cache; no EAV rows needed
        // — same shape the demo seeder writes).
        //
        // Sync is best-effort: a tenant created on the fly (e.g. by an
        // integration test that bypasses the full migrations) may not
        // have the built-in Asset ObjectType yet. The Asset row stays
        // valid even without a CatalogObject — it just won't appear in
        // `/api/assets` until the missing ObjectType is seeded.
        try {
            $catalogObjectId = $this->catalogAssetSync->syncFromUploadedAsset(
                assetId: $asset->getId(),
                code: $resolvedCode,
                indexedAttributes: $this->buildIndexedAttributes($asset),
            );
            $asset->linkToObject($catalogObjectId);
            $this->em->flush();
        } catch (Throwable $e) {
            $this->logger->warning(
                'CatalogAssetSync failed; Asset row stored without CatalogObject link.',
                ['asset_id' => $asset->getId()->toRfc4122(), 'exception' => $e],
            );
        }

        $this->bus->dispatch(new AssetThumbnailsRequested(
            assetId: $asset->getId(),
            tenantId: $tenant->getId(),
            storagePath: $storagePath,
            mimeType: $mimeType,
        ));

        return $asset;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildIndexedAttributes(Asset $asset): array
    {
        return [
            'mime' => $asset->getMimeType(),
            'filename' => $asset->getOriginalFilename(),
            'previewUrl' => \sprintf('/api/assets/%s/preview', $asset->getId()->toRfc4122()),
            'thumbnailsStatus' => $asset->getThumbnailsStatus()->value,
            'tags' => $asset->getTags(),
            'size' => $asset->getSize(),
            'width' => $asset->getWidth(),
            'height' => $asset->getHeight(),
            'pageCount' => $asset->getPageCount(),
            'folderCode' => $asset->getFolderCode(),
        ];
    }

    /**
     * Read the stored bytes back. Used by smoke tests + future signed-URL
     * generation.
     */
    public function read(Asset $asset): string
    {
        return $this->assetsStorage->read($asset->getStoragePath());
    }

    /**
     * #2944 — the name the operator sees. For a browser upload that is the
     * client-supplied name; for the ingestor and the CLI the file on disk
     * already carries a meaningful one.
     *
     * Untrusted by definition: it is stored and displayed, never used to
     * build a path.
     */
    private static function clientFilename(File $file): string
    {
        $name = $file instanceof UploadedFile ? $file->getClientOriginalName() : $file->getFilename();
        $name = trim(str_replace(["\0", "\r", "\n"], '', $name));
        // Defeat directory traversal at the source even though the value is
        // display-only: a name that reaches a template or a download header
        // must not read as a path.
        $name = basename($name);

        return '' !== $name ? $name : 'upload';
    }

    /**
     * Extension for the storage path. Derived from the sniffed MIME type
     * first — the client's extension is a hint, not evidence — and reduced
     * to a conservative character set before it can reach a path.
     */
    private static function storageExtension(File $file, string $originalFilename): string
    {
        $fromMime = $file->guessExtension();
        if (\is_string($fromMime) && '' !== $fromMime) {
            return self::safeExtension($fromMime);
        }

        $fromName = pathinfo($originalFilename, PATHINFO_EXTENSION);

        return '' !== $fromName ? self::safeExtension($fromName) : 'bin';
    }

    private static function safeExtension(string $extension): string
    {
        $safe = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $extension) ?? '');

        return '' !== $safe ? substr($safe, 0, 8) : 'bin';
    }

    private function resolveCode(?string $code, string $originalFilename, \App\Shared\Domain\Tenant $tenant): string
    {
        if (null !== $code && '' !== trim($code)) {
            return $code;
        }

        $base = pathinfo($originalFilename, PATHINFO_FILENAME);
        $slug = strtolower((string) $this->slugger->slug($base));
        if ('' === $slug) {
            $slug = 'asset';
        }

        $candidate = $slug;
        $suffix = 1;
        while (null !== $this->assets->findByCode($candidate, $tenant)) {
            ++$suffix;
            $candidate = $slug.'-'.$suffix;
        }

        return $candidate;
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function probeDimensions(string $path, string $mimeType): array
    {
        if (!MimeTypeWhitelist::isImage($mimeType) || 'image/svg+xml' === $mimeType) {
            return [null, null];
        }

        $info = @getimagesize($path);
        if (false === $info) {
            return [null, null];
        }

        return [$info[0], $info[1]];
    }
}
