<?php

declare(strict_types=1);

namespace App\Tests\Api\Asset;

use App\Asset\Application\AssetPreviewUrlSigner;
use App\Asset\Application\AssetUploader;
use App\Asset\Contracts\Service\AssetPreviewSigner;
use App\Catalog\Application\AssetPreviewUrlReadOverlay;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Uid\Uuid;

use const PHP_URL_QUERY;

/**
 * #2975 — the signed preview URL must carry the owning tenant.
 *
 * Production symptom: every thumbnail in the Multimedia grid answered
 * 404. The `<img>` request is anonymous, so nothing set the Postgres
 * `app.current_tenant` GUC; `assets` runs under FORCE ROW LEVEL SECURITY
 * with the strict policy `tenant_id = current_setting(…)::uuid`, so the
 * lookup saw zero rows and the controller raised "asset not found".
 *
 * The test suite cannot reproduce the RLS half (the test connection is
 * the table owner and bypasses RLS), so these cases pin the mechanism
 * that closes it: the tenant id travels inside the signature, and the
 * controller binds the tenant context from it.
 */
final class AssetPreviewTenantScopeApiTest extends CatalogApiTestCase
{
    #[Test]
    public function signedUrlCarriesTheOwningTenantInsideTheSignature(): void
    {
        $tenant = $this->resolveOrCreateTenant(self::TENANT_CODE);
        $signer = self::getContainer()->get(AssetPreviewUrlSigner::class);

        $signed = $signer->sign('11111111-1111-7111-8111-111111111111', null, $tenant->getId()->toRfc4122());

        parse_str((string) parse_url($signed, PHP_URL_QUERY), $params);
        self::assertSame($tenant->getId()->toRfc4122(), $params[AssetPreviewSigner::TENANT_PARAM] ?? null);
    }

    /**
     * The tenant id is covered by the HMAC: swapping it for another
     * tenant's must invalidate the signature, so it can never become a
     * cross-tenant selector.
     */
    #[Test]
    public function swappingTheTenantInvalidatesTheSignature(): void
    {
        $owner = $this->resolveOrCreateTenant(self::TENANT_CODE);
        $foreign = $this->resolveOrCreateTenant('acme');

        $signer = self::getContainer()->get(AssetPreviewUrlSigner::class);
        $signed = $signer->sign('11111111-1111-7111-8111-111111111111', null, $owner->getId()->toRfc4122());
        $tampered = str_replace($owner->getId()->toRfc4122(), $foreign->getId()->toRfc4122(), $signed);

        $client = static::createClient();
        $client->request('GET', $tampered);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * The read overlay — the surface the admin grid actually consumes —
     * must stamp the tenant onto every `previewUrl` it signs.
     */
    #[Test]
    public function readOverlaySignsPreviewUrlWithTheObjectTenant(): void
    {
        $tenant = $this->resolveOrCreateTenant(self::TENANT_CODE);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $objectId = $this->uploadAsset($tenant);
        self::assertNotNull($objectId, 'The uploaded asset must be linked to a CatalogObject.');

        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $object = $em->getRepository(CatalogObject::class)->find($objectId);
        self::assertInstanceOf(CatalogObject::class, $object);

        $overlay = self::getContainer()->get(AssetPreviewUrlReadOverlay::class);
        $signedUrl = $overlay->apply($object)->getAttributesIndexed()['previewUrl'] ?? null;

        self::assertIsString($signedUrl);
        self::assertStringContainsString(
            AssetPreviewSigner::TENANT_PARAM.'='.$tenant->getId()->toRfc4122(),
            rawurldecode($signedUrl),
        );
    }

    private function uploadAsset(Tenant $tenant): ?Uuid
    {
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $path = tempnam(sys_get_temp_dir(), 'pim-2975-');
        \assert(false !== $path);
        file_put_contents($path.'.txt', 'preview tenant scope bytes');
        rename($path, $path.'.txt');

        $asset = self::getContainer()->get(AssetUploader::class)->upload(new File($path.'.txt'), null);
        @unlink($path.'.txt');

        return $asset->getObjectId();
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
