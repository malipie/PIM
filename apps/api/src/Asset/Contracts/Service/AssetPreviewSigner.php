<?php

declare(strict_types=1);

namespace App\Asset\Contracts\Service;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;

/**
 * AUD-006 / #1576 — public port for minting and verifying the short-lived
 * HMAC-signed preview URLs that authorise `GET /api/assets/{id}/preview`.
 *
 * Lives in Asset\Contracts so the Catalog read surface (which signs the
 * denormalised `previewUrl` per request) depends only on this port, never
 * on Asset\Application internals (deptrac: Catalog → Asset_Contracts, see
 * ADR-0013). The implementation — {@see \App\Asset\Application\AssetPreviewUrlSigner}
 * — owns the TTL and the {@see \Symfony\Component\HttpFoundation\UriSigner}
 * wiring.
 *
 * The signature IS the auth factor (an `<img>` tag cannot attach a Bearer
 * header), so only an authenticated caller who can already read the asset
 * ever receives a signed URL, and the signature expires shortly after
 * issuance.
 */
interface AssetPreviewSigner
{
    /**
     * Query parameter carrying the RFC-4122 id of the tenant owning the
     * asset. It is part of the signed string, so it cannot be swapped for
     * another tenant's id without invalidating the signature. The preview
     * controller uses it to establish the Postgres RLS scope for an
     * anonymous `<img>` request, which has no principal to derive it from.
     */
    public const string TENANT_PARAM = 'tenant';

    /**
     * Returns a signed, relative preview URL for the asset id (RFC-4122).
     *
     * @param string|null            $tenantId RFC-4122 id of the tenant owning the
     *                                         asset. Travels INSIDE the signature so
     *                                         the anonymous `<img>` request can
     *                                         establish the Postgres RLS scope the
     *                                         asset lookup needs; a tampered value
     *                                         invalidates the signature.
     * @param DateTimeImmutable|null $now      test seam — when provided, the
     *                                         expiration is computed as $now + TTL
     *                                         so an expired URL can be produced
     *                                         deterministically in tests
     */
    public function sign(string $assetId, ?string $variant = null, ?string $tenantId = null, ?DateTimeImmutable $now = null): string;

    /**
     * Verifies the signature + expiration carried by the request's path +
     * query string. Returns false for a missing, tampered, or expired
     * signature.
     */
    public function verify(Request $request): bool;
}
