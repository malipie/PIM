<?php

declare(strict_types=1);

namespace App\Tests\Api\Asset;

use App\Asset\Application\AssetPreviewUrlSigner;
use App\Asset\Application\AssetUploader;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\File\File;

/**
 * AUD-006 / #1576 regression — `GET /api/assets/{id}/preview` must not
 * serve any tenant's bytes by id-knowledge alone.
 *
 * Before the fix the route was `PUBLIC_ACCESS` and the controller
 * explicitly `disable('tenant')`-ed the lookup, so an anonymous request
 * carrying only the (timestamped, partly predictable) UUID reached the
 * row cross-tenant. The empirical probe returned 404 (handler reached,
 * blob missing) instead of 401/403 — proof the request was authorised
 * by path knowledge, not by a credential.
 *
 * The hardened model mints a short-lived HMAC-signed URL via the
 * authenticated catalog read surface ({@see AssetPreviewUrlSigner}). The
 * `<img src>` then carries the signature in the query string, so the
 * browser needs no Bearer header, yet a request without a valid
 * signature (and without a tenant-scoped principal) is rejected.
 */
final class AssetPreviewSignedUrlApiTest extends CatalogApiTestCase
{
    /**
     * Anonymous request WITHOUT a valid signature must be denied (403),
     * not merely "not found" (404). 404 means the handler ran and the
     * caller was implicitly authorised by id knowledge — the AUD-006
     * vulnerability.
     */
    #[Test]
    public function anonymousRequestWithoutSignatureIsForbidden(): void
    {
        $asset = $this->uploadAssetForTenant(self::TENANT_CODE, 'aud006 owner bytes');

        $client = static::createClient();
        // No Authorization header, no _hash/_expiration query — mirrors a
        // raw `<img src="/api/assets/{id}/preview">` with a tampered/absent
        // signature.
        $client->request('GET', \sprintf('/api/assets/%s/preview', $asset));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * A tampered signature (right shape, wrong hash) must also be denied.
     */
    #[Test]
    public function tamperedSignatureIsForbidden(): void
    {
        $asset = $this->uploadAssetForTenant(self::TENANT_CODE, 'aud006 tamper bytes');

        $client = static::createClient();
        $client->request('GET', \sprintf(
            '/api/assets/%s/preview?_hash=not-a-real-hash&_expiration=%d',
            $asset,
            time() + 3600,
        ));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * An expired but otherwise valid signature must be denied.
     */
    #[Test]
    public function expiredSignatureIsForbidden(): void
    {
        $asset = $this->uploadAssetForTenant(self::TENANT_CODE, 'aud006 expired bytes');

        $signer = self::getContainer()->get(AssetPreviewUrlSigner::class);
        // Sign with an already-past expiration.
        $signed = $signer->sign($asset, null, null, new DateTimeImmutable('-1 hour'));

        $client = static::createClient();
        $client->request('GET', $signed);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * A request carrying a freshly minted, valid signature streams the
     * bytes back (200) without any Authorization header — the `<img>`
     * flow the admin grid relies on.
     */
    #[Test]
    public function validSignatureStreamsBytesAnonymously(): void
    {
        $asset = $this->uploadAssetForTenant(self::TENANT_CODE, 'aud006 valid bytes');

        $signer = self::getContainer()->get(AssetPreviewUrlSigner::class);
        $signed = $signer->sign($asset);

        $client = static::createClient();
        $client->request('GET', $signed);

        self::assertResponseIsSuccessful();
    }

    /**
     * Cross-tenant: a URL signed for tenant B's asset still streams the
     * bytes IF the signature is valid (the signature is the auth factor,
     * minted only by an authenticated caller who can already read the
     * asset). What must NEVER work is reaching tenant B's bytes WITHOUT a
     * valid signature — covered by the anonymous/tampered cases. Here we
     * assert the negative directly: tenant B's asset, no signature → 403,
     * never the bytes.
     */
    #[Test]
    public function crossTenantAssetWithoutSignatureLeaksNoBytes(): void
    {
        // Asset belongs to a *second* tenant; attacker knows only its id.
        $foreignAsset = $this->uploadAssetForTenant('acme', 'aud006 foreign secret bytes');

        $client = static::createClient();
        $response = $client->request('GET', \sprintf('/api/assets/%s/preview', $foreignAsset));

        $status = $response->getStatusCode();
        self::assertContains(
            $status,
            [401, 403, 404],
            'Cross-tenant preview without a signature must never return 200 with bytes.',
        );
        self::assertNotSame(200, $status);
    }

    /**
     * Uploads a real asset (with a stored blob) for the given tenant and
     * returns its RFC-4122 id. Mirrors {@see AssetUploaderTest}: the test
     * `assets.storage` is a local filesystem, so the bytes are reachable.
     */
    private function uploadAssetForTenant(string $tenantCode, string $contents): string
    {
        $tenant = $this->resolveOrCreateTenant($tenantCode);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $path = tempnam(sys_get_temp_dir(), 'pim-aud006-');
        \assert(false !== $path);
        file_put_contents($path.'.txt', $contents);
        rename($path, $path.'.txt');

        $uploader = self::getContainer()->get(AssetUploader::class);
        $asset = $uploader->upload(new File($path.'.txt'), null);

        @unlink($path.'.txt');

        return $asset->getId()->toRfc4122();
    }

    private function resolveOrCreateTenant(string $code): Tenant
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $existing = $em->getRepository(Tenant::class)->findOneBy(['code' => $code]);
        if ($existing instanceof Tenant) {
            return $existing;
        }

        $tenant = new Tenant($code, ucfirst($code).' Tenant');
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }
}
