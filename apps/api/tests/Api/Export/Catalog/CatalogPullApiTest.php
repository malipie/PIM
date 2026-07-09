<?php

declare(strict_types=1);

namespace App\Tests\Api\Export\Catalog;

use App\Catalog\Domain\ObjectKind;
use App\Export\Catalog\Application\Delivery\CatalogTokenService;
use App\Export\Catalog\Domain\Entity\CatalogProfile;
use App\Export\Catalog\Domain\Enum\CatalogTemplateKind;
use App\Export\Catalog\Domain\Repository\CatalogProfileRepositoryInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use DateTimeImmutable;
use League\Flysystem\FilesystemOperator;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * CPDF-P3-02 [SEC] — public catalog PDF pull via token URL (cache-and-serve).
 *
 * Pins the security contract of `GET /api/catalogs/pull/{tenantId}/{token}.pdf`:
 * an unknown token, a bad tenantId and a cross-tenant token all yield a flat
 * 404 (no enumeration signal); the endpoint streams the cached PDF only (never
 * generates); ETag drives a `304`; and the token mint/revoke lifecycle is gated
 * by `integration.admin` (403 for the `marketing` persona). Mirrors the feed
 * pull contract but for the PDF catalog surface.
 */
final class CatalogPullApiTest extends CatalogApiTestCase
{
    private const string MARKETING_EMAIL = 'marketing-cpdf-pull@demo.localhost';
    private const string PDF_BLOB = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    #[Test]
    public function unknownTokenIsAFlat404WithoutEnumeration(): void
    {
        $tenantId = $this->demoTenant()->getId()->toRfc4122();
        $randomToken = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

        $anon = static::createClient();
        self::assertSame(
            404,
            $anon->request('GET', '/api/catalogs/pull/'.$tenantId.'/'.$randomToken.'.pdf')->getStatusCode(),
        );
    }

    #[Test]
    public function badTenantIdIsA404(): void
    {
        $unknownTenant = Uuid::v4()->toRfc4122();
        $randomToken = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

        $anon = static::createClient();
        self::assertSame(
            404,
            $anon->request('GET', '/api/catalogs/pull/'.$unknownTenant.'/'.$randomToken.'.pdf')->getStatusCode(),
        );
    }

    #[Test]
    public function happyPathStreamsTheCachedPdfWithAnEtag(): void
    {
        $tenant = $this->demoTenant();
        [$token, $catalogId] = $this->seedCachedCatalog($tenant, 'happy_catalog');
        $url = '/api/catalogs/pull/'.$tenant->getId()->toRfc4122().'/'.$token.'.pdf';
        \assert('' !== $catalogId);

        $anon = static::createClient();
        $response = $anon->request('GET', $url);
        self::assertSame(200, $response->getStatusCode());

        $headers = $response->getHeaders(throw: false);
        self::assertSame('application/pdf', $headers['content-type'][0] ?? null);
        $etag = $headers['etag'][0] ?? null;
        self::assertNotNull($etag);

        self::assertStringStartsWith('%PDF-', $response->getContent(false));
    }

    #[Test]
    public function aMatchingIfNoneMatchReturns304(): void
    {
        $tenant = $this->demoTenant();
        [$token] = $this->seedCachedCatalog($tenant, 'revalidated_catalog');
        $url = '/api/catalogs/pull/'.$tenant->getId()->toRfc4122().'/'.$token.'.pdf';

        $anon = static::createClient();
        $first = $anon->request('GET', $url);
        self::assertSame(200, $first->getStatusCode());
        $etag = $first->getHeaders(throw: false)['etag'][0] ?? null;
        self::assertNotNull($etag);

        $revalidated = $anon->request('GET', $url, ['headers' => ['If-None-Match' => $etag]]);
        self::assertSame(304, $revalidated->getStatusCode());
    }

    #[Test]
    public function aTokenMintedForTenantACannotPullUnderTenantB(): void
    {
        $tenantA = $this->demoTenant();
        [$token] = $this->seedCachedCatalog($tenantA, 'cross_tenant_catalog');

        // A different (real) tenant id in the path → the token's HMAC cannot
        // resolve under B's RLS scope, so this is a flat 404.
        $tenantB = new Tenant('other-cpdf', 'Other Tenant');
        $this->em()->persist($tenantB);
        $this->em()->flush();

        $anon = static::createClient();
        self::assertSame(
            404,
            $anon->request('GET', '/api/catalogs/pull/'.$tenantB->getId()->toRfc4122().'/'.$token.'.pdf')->getStatusCode(),
        );
    }

    #[Test]
    public function aCatalogWithATokenButNoCachedFileIs404NeverGenerates(): void
    {
        $tenant = $this->demoTenant();
        $this->scopeToTenant($tenant);
        // Mint a token but DO NOT write a cache blob / set the cache pointer.
        $catalog = new CatalogProfile(
            'uncached_catalog',
            'Uncached',
            CatalogTemplateKind::Sheet,
            Uuid::fromString($this->objectTypeIdFor(ObjectKind::Product)),
        );
        $catalog->assignTenant($tenant);
        $token = self::getContainer()->get(CatalogTokenService::class)->mint($catalog);
        $this->catalogRepo()->save($catalog);

        $url = '/api/catalogs/pull/'.$tenant->getId()->toRfc4122().'/'.$token.'.pdf';
        $anon = static::createClient();
        self::assertSame(404, $anon->request('GET', $url)->getStatusCode());
    }

    #[Test]
    public function tokenMintIsAdminOnlyAndRevocable(): void
    {
        $admin = $this->authenticatedClient();

        $created = $admin->request('POST', '/api/catalogs', ['json' => [
            'template_kind' => 'sheet',
            'code' => 'token_lifecycle_catalog',
            'name' => 'Token lifecycle',
            'object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
            'field_mappings' => [['slot' => 'title', 'source' => ['kind' => 'attribute', 'ref' => 'name']]],
            'locale' => 'pl',
        ]]);
        self::assertSame(201, $created->getStatusCode());
        $id = $created->toArray(false)['id'];
        \assert(\is_string($id));

        // Admin (super_admin + tenant_owner → integration.admin) can mint.
        $minted = $admin->request('POST', '/api/catalogs/'.$id.'/token');
        self::assertSame(201, $minted->getStatusCode());
        $mintedBody = $minted->toArray(false);
        self::assertIsString($mintedBody['token']);
        self::assertIsString($mintedBody['url']);
        self::assertStringContainsString('/api/catalogs/pull/', $mintedBody['url']);
        self::assertStringEndsWith('.pdf', $mintedBody['url']);

        // The marketing persona lacks integration.admin → 403 on mint + revoke.
        $marketingJwt = $this->seedMarketingUser();
        $client = static::createClient();
        $client->request('POST', '/api/catalogs/'.$id.'/token', [
            'headers' => ['authorization' => 'Bearer '.$marketingJwt],
        ]);
        self::assertResponseStatusCodeSame(403);

        $client->request('DELETE', '/api/catalogs/'.$id.'/token', [
            'headers' => ['authorization' => 'Bearer '.$marketingJwt],
        ]);
        self::assertResponseStatusCodeSame(403);

        // Admin revokes.
        self::assertSame(204, $admin->request('DELETE', '/api/catalogs/'.$id.'/token')->getStatusCode());
    }

    /**
     * Seed a tenant-scoped CatalogProfile with a minted token AND a cached PDF
     * blob written directly to the exports storage under the CPDF cache key.
     *
     * @return array{0: string, 1: string} [plaintext token, catalog id rfc4122]
     */
    private function seedCachedCatalog(Tenant $tenant, string $code): array
    {
        $this->scopeToTenant($tenant);
        $catalog = new CatalogProfile(
            $code,
            'Cached catalog',
            CatalogTemplateKind::Sheet,
            Uuid::fromString($this->objectTypeIdFor(ObjectKind::Product)),
        );
        $catalog->assignTenant($tenant);

        $key = sprintf(
            'catalogs/%s/%s.pdf',
            $tenant->getId()->toRfc4122(),
            $catalog->getId()->toRfc4122(),
        );
        $blob = self::PDF_BLOB;
        $this->exportsStorage()->write($key, $blob);

        $catalog->recordCache($key, \strlen($blob), 1, new DateTimeImmutable());
        $token = self::getContainer()->get(CatalogTokenService::class)->mint($catalog);
        $this->catalogRepo()->save($catalog);

        return [$token, $catalog->getId()->toRfc4122()];
    }

    private function demoTenant(): Tenant
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }

    /**
     * Establish the RLS scope (GUC + TenantContext + Doctrine filter) so a
     * direct repository save of a CatalogProfile passes row-level security —
     * the same context the public controller sets from the URL path.
     */
    private function scopeToTenant(Tenant $tenant): void
    {
        $this->em()->getConnection()->executeStatement(
            "SELECT set_config('app.current_tenant', :tenant, false)",
            ['tenant' => $tenant->getId()->toRfc4122()],
        );
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();
    }

    private function catalogRepo(): CatalogProfileRepositoryInterface
    {
        $repo = self::getContainer()->get(CatalogProfileRepositoryInterface::class);

        return $repo;
    }

    private function exportsStorage(): FilesystemOperator
    {
        $storage = self::getContainer()->get('exports.storage');

        return $storage;
    }

    private function seedMarketingUser(): string
    {
        $em = $this->em();
        $tenant = $this->demoTenant();

        $marketing = self::getContainer()->get(RoleRepositoryInterface::class)
            ->findByCode('marketing', $tenant);
        \assert(null !== $marketing, 'marketing role must be seeded per tenant');

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stub = new User($tenant, self::MARKETING_EMAIL, '', ['ROLE_USER']);
        $user = new User($tenant, self::MARKETING_EMAIL, $hasher->hashPassword($stub, 'changeme'), ['ROLE_USER']);
        $user->addRole($marketing);
        $em->persist($user);
        $em->flush();

        $existing = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail(self::MARKETING_EMAIL);
        \assert($existing instanceof User);

        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($existing);
    }
}
