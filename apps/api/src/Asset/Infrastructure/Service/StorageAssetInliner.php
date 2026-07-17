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
 * variant selection, but prefers the THUMB (200px) variant.
 *
 * Why thumb, not medium: Dompdf (the default in-process renderer) decodes every
 * embedded image to a raw bitmap and holds the whole document in PHP memory. An
 * 800px JPEG expands to ~1.9 MB raw; a few hundred of them blow past the worker's
 * 256 MB ceiling and the render dies with an OOM Fatal — so a catalog with images
 * would not generate at all. A 200px thumb is ~16× smaller raw, keeping a
 * full-cap (CATALOG_PDF_MAX_ITEMS) catalog inside the budget. Higher-fidelity
 * output is the job of the Gotenberg sidecar (streams, no in-memory cap).
 *
 * Resolutions are memoised per instance: a catalog render resolves the same
 * logo once per document instead of once per product row.
 */
final class StorageAssetInliner implements AssetInliner
{
    /** @var array<string, string|null> "variant|reference" => data URI (or null miss) */
    private array $cache = [];

    public function __construct(
        private readonly AssetRepositoryInterface $assets,
        private readonly FilesystemOperator $assetsStorage,
    ) {
    }

    public function toDataUri(string $reference, ?string $preferredVariant = null): ?string
    {
        $key = ($preferredVariant ?? '').'|'.$reference;
        if (\array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->resolve($reference, $preferredVariant);
    }

    private function resolve(string $reference, ?string $preferredVariant): ?string
    {
        $assetId = $this->extractId($reference);
        if (null === $assetId) {
            return null;
        }

        $asset = $this->assets->findById($assetId) ?? $this->assets->findByObjectId($assetId);
        if (null === $asset) {
            return null;
        }

        [$path, $mime] = $this->resolveVariant($asset, $preferredVariant);

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
    private function resolveVariant(Asset $asset, ?string $preferredVariant): array
    {
        $variants = [];
        foreach ($asset->getVariants() as $variant) {
            $variants[$variant->getVariantCode()] = [$variant->getStoragePath(), $variant->getMimeType()];
        }

        // Memory-first order thumb (200) → medium (800) → original: the smallest
        // variant that exists keeps the in-process renderer under its budget
        // (see the class docblock). A caller-preferred variant (#2608 — sheet
        // catalogs under the HQ cap ask for medium) jumps the queue; the
        // fallback chain stays memory-first. Only used when thumbnails are ready.
        if (ThumbnailsStatus::Ready === $asset->getThumbnailsStatus()) {
            $order = [AssetVariant::CODE_THUMB, AssetVariant::CODE_MEDIUM, AssetVariant::CODE_ORIGINAL];
            if (null !== $preferredVariant) {
                array_unshift($order, $preferredVariant);
            }
            foreach (array_unique($order) as $code) {
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
