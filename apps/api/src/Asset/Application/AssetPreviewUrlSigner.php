<?php

declare(strict_types=1);

namespace App\Asset\Application;

use App\Asset\Contracts\Service\AssetPreviewSigner;
use DateInterval;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;

use const PHP_QUERY_RFC3986;

/**
 * AUD-006 / #1576 — mints and verifies short-lived HMAC-signed preview
 * URLs for `GET /api/assets/{id}/preview`.
 *
 * Why signed URLs instead of a Bearer-gated endpoint: the admin renders
 * thumbnails with `<img src="/api/assets/{id}/preview?…">`, and an
 * `<img>` tag cannot attach an `Authorization` header. Before the fix the
 * route was simply `PUBLIC_ACCESS` and the controller disabled the tenant
 * filter, so anyone holding the (timestamped, partly predictable) UUID
 * could stream any tenant's bytes anonymously.
 *
 * The signature IS the auth factor — exactly the model the magic-link and
 * SSO-callback routes in `security.yaml` already use. Only an
 * authenticated caller who can read the asset ever receives a signed URL
 * (the catalog read providers sign `previewUrl` per-request), and the
 * signature expires after {@see self::TTL}, so a leaked URL stops working
 * shortly after issuance instead of forever.
 *
 * The signed query string also carries the owning tenant
 * ({@see AssetPreviewSigner::TENANT_PARAM}). An anonymous `<img>` request
 * has no principal, so nothing would set the Postgres `app.current_tenant`
 * GUC that the `assets` RLS policy reads — the row lookup would return
 * zero rows and the endpoint would answer 404 for every thumbnail in
 * production. The tenant id is not a secret (it already appears in the
 * public catalog-pull URLs), and because it is covered by the HMAC it
 * cannot be swapped for another tenant's: tampering invalidates the
 * signature before any query runs.
 *
 * Signing/verification deliberately operate on the path + query string
 * only (never scheme/host) so a relative `previewUrl` persisted in
 * `attributes_indexed` verifies identically regardless of the public
 * host (`pim.localhost` dev vs `pim.example.com` prod vs the BrowserKit
 * test host). {@see UriSigner::check()} hashes the string as-is, unlike
 * {@see UriSigner::checkRequest()} which prepends the request scheme+host.
 */
final readonly class AssetPreviewUrlSigner implements AssetPreviewSigner
{
    /**
     * URL lifetime. Long enough that a thumbnail survives a slow page +
     * browser cache (`Cache-Control: private, max-age=300`), short enough
     * that a leaked URL (logs, referrer, export) is useless within the
     * hour rather than indefinitely.
     */
    private const string TTL = 'PT1H';

    public function __construct(
        private UriSigner $uriSigner,
    ) {
    }

    /**
     * Returns a signed, relative preview URL for the asset id (RFC-4122).
     *
     * @param string|null            $tenantId RFC-4122 id of the owning tenant,
     *                                         carried inside the signature (see
     *                                         the class docblock)
     * @param DateTimeImmutable|null $now      test seam — when provided, the
     *                                         expiration is computed as $now + TTL
     *                                         so an expired URL can be produced
     *                                         deterministically in tests
     */
    public function sign(string $assetId, ?string $variant = null, ?string $tenantId = null, ?DateTimeImmutable $now = null): string
    {
        $uri = $this->buildUri($assetId, $variant, $tenantId);
        $expiration = ($now ?? new DateTimeImmutable())->add(new DateInterval(self::TTL));

        return $this->uriSigner->sign($uri, $expiration);
    }

    /**
     * Verifies the signature + expiration carried by the request's path +
     * query string. Returns false for a missing, tampered, or expired
     * signature.
     */
    public function verify(Request $request): bool
    {
        $qs = $request->server->get('QUERY_STRING');
        $uri = $request->getPathInfo().(\is_string($qs) && '' !== $qs ? '?'.$qs : '');

        return $this->uriSigner->check($uri);
    }

    private function buildUri(string $assetId, ?string $variant, ?string $tenantId): string
    {
        $query = [];
        if (null !== $variant && '' !== $variant) {
            $query['variant'] = $variant;
        }
        if (null !== $tenantId && '' !== $tenantId) {
            $query[AssetPreviewSigner::TENANT_PARAM] = $tenantId;
        }

        $uri = \sprintf('/api/assets/%s/preview', $assetId);
        if ([] !== $query) {
            $uri .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $uri;
    }
}
