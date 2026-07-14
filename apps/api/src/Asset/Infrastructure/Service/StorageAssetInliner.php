<?php

declare(strict_types=1);

namespace App\Asset\Infrastructure\Service;

use App\Asset\Contracts\Service\AssetInliner;
use App\Asset\Domain\Entity\Asset;
use App\Asset\Domain\Entity\AssetVariant;
use App\Asset\Domain\Repository\AssetRepositoryInterface;
use App\Asset\Domain\ThumbnailsStatus;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Uid\Uuid;

/**
 * Reads an asset's bytes straight from storage and base64-encodes them into a
 * `data:` URI (CPDF #2569/#2570). Mirrors {@see \App\Asset\Presentation\Controller\PreviewAssetController}
 * variant selection, but prefers the medium (800px) variant — a print-friendly
 * size that keeps the PDF small without the thumbnail's loss of detail.
 *
 * Resolutions are memoised per instance: a catalog render resolves the same
 * logo once per document instead of once per product row.
 */
final class StorageAssetInliner implements AssetInliner
{
    /** @var array<string, string|null> reference => data URI (or null miss) */
    private array $cache = [];

    public function __construct(
        private readonly AssetRepositoryInterface $assets,
        private readonly FilesystemOperator $assetsStorage,
    ) {
    }

    public function toDataUri(string $reference): ?string
    {
        if (\array_key_exists($reference, $this->cache)) {
            return $this->cache[$reference];
        }

        return $this->cache[$reference] = $this->resolve($reference);
    }

    private function resolve(string $reference): ?string
    {
        $assetId = $this->extractId($reference);
        if (null === $assetId) {
            return null;
        }

        $asset = $this->assets->findById($assetId) ?? $this->assets->findByObjectId($assetId);
        if (null === $asset) {
            return null;
        }

        [$path, $mime] = $this->resolveVariant($asset);

        try {
            $bytes = $this->assetsStorage->read($path);
        } catch (FilesystemException) {
            return null;
        }

        return \sprintf('data:%s;base64,%s', $mime, base64_encode($bytes));
    }

    /**
     * Accepts a bare UUID or a `/api/assets/{id}/preview` URL (with or without
     * a query string). Returns null for anything else — an external image URL
     * or plain text passes through untouched.
     */
    private function extractId(string $reference): ?Uuid
    {
        $candidate = $reference;
        if (1 === preg_match('#/api/assets/([^/]+)/preview#', $reference, $matches)) {
            $candidate = $matches[1];
        }

        return Uuid::isValid($candidate) ? Uuid::fromString($candidate) : null;
    }

    /**
     * @return array{0: string, 1: string} [storagePath, mimeType]
     */
    private function resolveVariant(Asset $asset): array
    {
        $variants = [];
        foreach ($asset->getVariants() as $variant) {
            $variants[$variant->getVariantCode()] = [$variant->getStoragePath(), $variant->getMimeType()];
        }

        // Print prefers medium (800) → thumb (200) → original when thumbnails
        // are ready; falls back to the original blob otherwise.
        if (ThumbnailsStatus::Ready === $asset->getThumbnailsStatus()) {
            foreach ([AssetVariant::CODE_MEDIUM, AssetVariant::CODE_THUMB, AssetVariant::CODE_ORIGINAL] as $code) {
                if (isset($variants[$code])) {
                    return $variants[$code];
                }
            }
        }

        if (isset($variants[AssetVariant::CODE_ORIGINAL])) {
            return $variants[AssetVariant::CODE_ORIGINAL];
        }

        return [$asset->getStoragePath(), $asset->getMimeType()];
    }
}
