<?php

declare(strict_types=1);

namespace App\Asset\Presentation\Controller;

use App\Asset\Contracts\Service\AssetPreviewSigner;
use App\Asset\Domain\Entity\AssetVariant;
use App\Asset\Domain\Repository\AssetRepositoryInterface;
use App\Asset\Domain\ThumbnailsStatus;
use App\Identity\Contracts\Attribute\NoPermissionRequired;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Repository\TenantRepositoryInterface;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /api/assets/{id}/preview[?variant=thumb|medium|original]` (#438,
 * hardened in AUD-006 / #1576).
 *
 * Single-origin preview surface — `<img src="/api/assets/{id}/preview?…"
 * />` works from the admin without exposing MinIO directly to the
 * browser (CSP allows only `'self'` and `data:` for img-src). Defaults
 * to the `thumb` variant when ready, falls back to `medium` →
 * `original` so the grid never shows a broken image.
 *
 * Streams the bytes back through Flysystem (`readStream`) so a 50 MB
 * PDF does not balloon the worker memory. `Cache-Control: private,
 * max-age=...` lets the browser cache previews across navigations
 * inside the same session.
 *
 * Auth model (AUD-006): the request must carry a valid, unexpired
 * HMAC signature minted by {@see AssetPreviewSigner}. An `<img>` tag
 * cannot send a Bearer header, so the signature — embedded in the query
 * string of the `previewUrl` the catalog read API hands out to an
 * authenticated caller — IS the auth factor (same model as the
 * magic-link / SSO-callback routes). Without a valid signature the
 * request is rejected with 403 before any row is loaded, closing the
 * pre-fix hole where id-knowledge alone streamed any tenant's bytes.
 *
 * Tenant scope (#2975): an anonymous signed request has no principal, so
 * neither the Doctrine `tenant` filter nor — decisively — the Postgres
 * `app.current_tenant` GUC gets a value. `assets` carries FORCE ROW LEVEL
 * SECURITY with the strict policy `tenant_id = current_setting(…)::uuid`,
 * so an unset GUC yields zero rows and every thumbnail answered 404 in
 * production (dev never showed it: the non-prod APP_DEFAULT_TENANT_CODE
 * fallback resolves a tenant for anonymous requests). The signed URL
 * therefore carries the owning tenant ({@see AssetPreviewSigner::TENANT_PARAM}),
 * and this controller establishes the RLS scope from it AFTER the signature
 * verified — the same construction {@see \App\Export\Catalog\Presentation\Controller\CatalogPullController}
 * uses. Because the value is covered by the HMAC it cannot be swapped for
 * another tenant's id, so this is not a cross-tenant vector. An
 * authenticated caller keeps its own tenant scope: the scope is only
 * established when no tenant is in context.
 */
final readonly class PreviewAssetController
{
    public function __construct(
        private AssetRepositoryInterface $assets,
        private FilesystemOperator $assetsStorage,
        private AssetPreviewSigner $urlSigner,
        private RequestStack $requestStack,
        private TenantRepositoryInterface $tenants,
        private TenantContext $tenantContext,
        private TenantFilterConfigurator $tenantFilter,
        private Connection $connection,
    ) {
    }

    #[Route(path: '/api/assets/{id}/preview', name: 'pim_assets_preview', methods: ['GET'])]
    #[NoPermissionRequired(reason: 'Authorised by a short-lived HMAC signature (AssetPreviewUrlSigner), not by RBAC: <img> tags cannot send a Bearer token, so the signed query string IS the auth factor. The signature is verified in the handler before any row loads; an unsigned/expired/tampered request gets 403. A RequiresPermission gate here would 403 every legitimate <img> request (anonymous principal) and break all thumbnails.')]
    public function __invoke(string $id, ?string $variant = null): StreamedResponse
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || !$this->urlSigner->verify($request)) {
            // No valid signature → reject before touching the database, so
            // id-knowledge alone never reaches (let alone streams) a row.
            throw new AccessDeniedHttpException('A valid, unexpired preview signature is required.');
        }

        $this->scopeToSignedTenant($request);

        $assetId = Uuid::fromString($id);
        $asset = $this->assets->findById($assetId) ?? $this->assets->findByObjectId($assetId);

        if (null === $asset) {
            throw new NotFoundHttpException(\sprintf('Asset "%s" was not found.', $id));
        }

        [$path, $mime] = $this->resolveVariant($asset, $variant);

        try {
            $stream = $this->assetsStorage->readStream($path);
        } catch (FilesystemException $e) {
            throw new NotFoundHttpException(\sprintf('Variant blob missing for asset "%s".', $id), $e);
        }

        $response = new StreamedResponse(static function () use ($stream): void {
            if (\is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        });
        $response->headers->set('content-type', $mime);
        $response->headers->set('cache-control', 'private, max-age=300');

        return $response;
    }

    /**
     * Establishes the RLS scope from the tenant id carried inside the
     * verified signature, so the asset lookup below sees rows at all.
     *
     * No-op when the request already runs under a resolved tenant (an
     * authenticated caller keeps its own scope) or when the URL predates
     * this parameter — such a URL is re-signed with the tenant on the next
     * catalog read, so the gap closes on its own.
     */
    private function scopeToSignedTenant(Request $request): void
    {
        if ($this->tenantContext->get() instanceof Tenant) {
            return;
        }

        $tenantId = $request->query->get(AssetPreviewSigner::TENANT_PARAM);
        if (!\is_string($tenantId) || !Uuid::isValid($tenantId)) {
            return;
        }

        $tenant = $this->tenants->findById(Uuid::fromString($tenantId));
        if (!$tenant instanceof Tenant) {
            return;
        }

        // tenant-safe: infrastructure (establishes the tenant_id the RLS policies read, taken from the HMAC-signed query string; this IS the tenant boundary the signature gates, not a bypass — mirrors CatalogPullController)
        $this->connection->executeStatement(
            "SELECT set_config('app.current_tenant', :tenant_id, false)",
            ['tenant_id' => $tenant->getId()->toRfc4122()],
        );
        $this->tenantContext->set($tenant);
        // Re-apply the Doctrine filter to the context we just set; without it
        // the filter keeps whatever the previous request on this worker
        // configured.
        $this->tenantFilter->apply();
    }

    /**
     * @return array{0: string, 1: string} [storagePath, mimeType]
     */
    private function resolveVariant(\App\Asset\Domain\Entity\Asset $asset, ?string $requested): array
    {
        $variants = [];
        foreach ($asset->getVariants() as $variant) {
            $variants[$variant->getVariantCode()] = [$variant->getStoragePath(), $variant->getMimeType()];
        }

        $preferred = match ($requested) {
            'thumb', 'medium', 'original' => $requested,
            default => null,
        };

        if (null !== $preferred && isset($variants[$preferred])) {
            return $variants[$preferred];
        }

        // Default order: thumb (200) → medium (800) → original. Browser
        // dla grida zachowuje pasmo gdy thumb jest dostępny.
        if (ThumbnailsStatus::Ready === $asset->getThumbnailsStatus()) {
            foreach ([AssetVariant::CODE_THUMB, AssetVariant::CODE_MEDIUM, AssetVariant::CODE_ORIGINAL] as $code) {
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
