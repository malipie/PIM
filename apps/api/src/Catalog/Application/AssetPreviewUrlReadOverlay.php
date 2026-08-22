<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Asset\Contracts\Service\AssetPreviewSigner;
use App\Catalog\Domain\Entity\CatalogObject;

use const PHP_URL_QUERY;

/**
 * AUD-006 / #1576 — read-only overlay that replaces the bare,
 * persisted `previewUrl` in `attributes_indexed` with a freshly minted,
 * short-lived HMAC-signed URL on every GET.
 *
 * `previewUrl` is denormalised into `attributes_indexed` at upload time
 * (see {@see \App\Asset\Application\AssetUploader::buildIndexedAttributes()})
 * as the bare path `/api/assets/{id}/preview`. Persisting a signed URL
 * instead is impossible — the signature expires. So, mirroring
 * {@see SystemAttributeReadOverlay}, we sign per-request on a clone using
 * the side-effect-free {@see CatalogObject::overlayAttributesIndexedForRead()}
 * so a GET never touches `updatedAt` or risks persistence.
 *
 * The signed URL keeps the `<img src>` flow working: the browser sends
 * no Bearer header, but the query-string signature authorises the
 * preview endpoint. It also carries the owning tenant (#2975) so the
 * anonymous request can establish the RLS scope the asset lookup needs. The signature is only ever handed to a caller who
 * has already passed RBAC on the catalog read surface.
 *
 * Idempotent + defensive: only values shaped like an asset preview path
 * are signed, and an already-signed value (carrying `_hash`) is left
 * untouched, so applying the overlay twice or over an unrelated URL is a
 * no-op.
 */
final readonly class AssetPreviewUrlReadOverlay
{
    private const string PREVIEW_KEY = 'previewUrl';

    public function __construct(
        private AssetPreviewSigner $signer,
    ) {
    }

    public function apply(CatalogObject $object): CatalogObject
    {
        $indexed = $object->getAttributesIndexed();
        if (!\array_key_exists(self::PREVIEW_KEY, $indexed)) {
            return $object;
        }

        $value = $indexed[self::PREVIEW_KEY];

        // `previewUrl` is stored as a bare string, but tolerate the
        // envelope `{value: …}` shape some writers use.
        $tenantId = $object->getTenant()?->getId()->toRfc4122();

        if (\is_string($value)) {
            $signed = $this->signPath($value, $object->getId()->toRfc4122(), $tenantId);
            if (null === $signed) {
                return $object;
            }
            $indexed[self::PREVIEW_KEY] = $signed;
        } elseif (\is_array($value) && isset($value['value']) && \is_string($value['value'])) {
            $signed = $this->signPath($value['value'], $object->getId()->toRfc4122(), $tenantId);
            if (null === $signed) {
                return $object;
            }
            $value['value'] = $signed;
            $indexed[self::PREVIEW_KEY] = $value;
        } else {
            return $object;
        }

        $copy = clone $object;
        $copy->overlayAttributesIndexedForRead($indexed);

        return $copy;
    }

    /**
     * Signs a bare `/api/assets/{id}/preview[?variant=…]` path. Returns
     * null (caller keeps the original) when the value is not a preview
     * path or already carries a signature.
     */
    private function signPath(string $path, string $assetId, ?string $tenantId): ?string
    {
        if (!str_contains($path, '/api/assets/') || !str_contains($path, '/preview')) {
            return null;
        }
        if (str_contains($path, '_hash=')) {
            // Already signed (e.g. overlay applied twice) — leave as-is.
            return null;
        }

        $variant = null;
        $query = parse_url($path, PHP_URL_QUERY);
        if (\is_string($query) && '' !== $query) {
            parse_str($query, $params);
            $candidate = $params['variant'] ?? null;
            $variant = \is_string($candidate) && '' !== $candidate ? $candidate : null;
        }

        return $this->signer->sign($assetId, $variant, $tenantId);
    }
}
