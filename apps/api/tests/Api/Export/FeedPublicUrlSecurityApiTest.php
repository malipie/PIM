<?php

declare(strict_types=1);

namespace App\Tests\Api\Export;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Catalog\Application\BuiltInObjectTypeSeeder;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Export\Feed\Application\Delivery\FeedTokenService;
use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Entity\FeedRun;
use App\Export\Feed\Domain\Enum\FeedRunTrigger;
use App\Export\Feed\Domain\Enum\FeedTemplateKind;
use App\Export\Feed\Domain\Message\RunFeedMessage;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * XMLF-P6-02 [SEC] — the public feed URL as an attack surface (plan §6.9/§11).
 *
 * The URL token is the only credential: this suite proves, with a live
 * Postgres and two tenants, that a token never crosses the tenant boundary,
 * that any miss is an enumeration-resistant 404 (never 401/403), that the
 * token clears the 128-bit entropy floor and stays opaque, that the endpoint
 * only ever streams the cached artifact (never generates), and that the token
 * lifecycle (rotate/revoke, hash never serialized) holds.
 */
final class FeedPublicUrlSecurityApiTest extends CatalogApiTestCase
{
    #[Test]
    public function feedsServeOnlyTheirOwnTenantsProductsBothWays(): void
    {
        $admin = $this->authenticatedClient();

        // Tenant A (demo) — one product, one feed, generated cache.
        $this->seedProduct($this->demoTenant(), 'SKU-ALPHA');
        $feedA = $this->createFeed($admin, 'alpha_feed');
        self::assertSame(202, $admin->request('POST', '/api/feeds/'.$feedA.'/regenerate')->getStatusCode());
        $urlA = $this->mintPullUrl($admin, $feedA);

        // Tenant B — seeded straight through the EM (no API session exists for
        // it, which is the point: the public URL must not need one).
        [$tenantB, $urlB] = $this->seedForeignTenantWithFeed('SKU-BETA');

        $anon = static::createClient();

        $xmlA = $anon->request('GET', $urlA)->getContent(false);
        self::assertStringContainsString('SKU-ALPHA', $xmlA);
        self::assertStringNotContainsString('SKU-BETA', $xmlA, 'tenant A feed must not leak tenant B products');

        $xmlB = $anon->request('GET', $urlB)->getContent(false);
        self::assertStringContainsString('SKU-BETA', $xmlB);
        self::assertStringNotContainsString('SKU-ALPHA', $xmlB, 'tenant B feed must not leak tenant A products');

        // A valid token replayed under the OTHER tenant's path is a plain 404.
        $tokenA = $this->tokenFromUrl($urlA);
        $tokenB = $this->tokenFromUrl($urlB);
        $demoId = $this->demoTenant()->getId()->toRfc4122();
        $betaId = $tenantB->getId()->toRfc4122();

        self::assertSame(404, $anon->request('GET', '/api/feeds/pull/'.$betaId.'/'.$tokenA.'.xml')->getStatusCode());
        self::assertSame(404, $anon->request('GET', '/api/feeds/pull/'.$demoId.'/'.$tokenB.'.xml')->getStatusCode());
    }

    #[Test]
    public function anyMissIsA404NeverAnAuthError(): void
    {
        $admin = $this->authenticatedClient();
        $feedId = $this->createFeed($admin, 'miss_feed');
        $this->mintPullUrl($admin, $feedId);

        $anon = static::createClient();
        $tenantId = $this->demoTenant()->getId()->toRfc4122();

        // Wrong token on a real tenant, and a real-shaped token on a tenant
        // that does not exist: both are indistinguishable 404s (no 401/403 —
        // the endpoint never reveals whether a feed exists).
        $wrongToken = $anon->request('GET', '/api/feeds/pull/'.$tenantId.'/'.str_repeat('x', 32).'.xml');
        self::assertSame(404, $wrongToken->getStatusCode());

        $ghostTenant = $anon->request('GET', '/api/feeds/pull/'.Uuid::v7()->toRfc4122().'/'.str_repeat('y', 32).'.xml');
        self::assertSame(404, $ghostTenant->getStatusCode());
    }

    #[Test]
    public function tokenClearsTheEntropyFloorAndStaysOpaque(): void
    {
        $admin = $this->authenticatedClient();
        $feedId = $this->createFeed($admin, 'entropy_feed');

        $first = $this->mintToken($admin, $feedId);
        $second = $this->mintToken($admin, $feedId); // rotate

        foreach ([$first, $second] as $token) {
            // 32 chars of base64url = 6 bits/char → 192-bit ≥ the 128-bit floor.
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
            self::assertGreaterThanOrEqual(22, \strlen($token), '128 bits need ≥22 base64url chars');

            // Opaque: no predictable id-derived prefix (token ≠ feed/tenant id).
            $flat = strtolower($token);
            self::assertStringNotContainsString(substr(str_replace('-', '', $feedId), 0, 8), $flat);
            self::assertStringNotContainsString(
                substr(str_replace('-', '', $this->demoTenant()->getId()->toRfc4122()), 0, 8),
                $flat,
            );
        }

        self::assertNotSame($first, $second, 'rotation must mint a fresh token');
    }

    #[Test]
    public function rateLimitAppliesToServedPullsToo(): void
    {
        $admin = $this->authenticatedClient();
        $feedId = $this->createFeed($admin, 'served_limit_feed');
        self::assertSame(202, $admin->request('POST', '/api/feeds/'.$feedId.'/regenerate')->getStatusCode());
        $url = $this->mintPullUrl($admin, $feedId);

        // The P3-06 telemetry suite pins the uncached 404 variant; this pins
        // the served path — the budget (5/h in the test env) burns on 200s.
        $anon = static::createClient();
        for ($i = 1; $i <= 5; ++$i) {
            self::assertSame(200, $anon->request('GET', $url)->getStatusCode(), 'Serve #'.$i.' within budget.');
        }

        $throttled = $anon->request('GET', $url);
        self::assertSame(429, $throttled->getStatusCode());
        $retryAfter = $throttled->getHeaders(throw: false)['retry-after'][0] ?? null;
        self::assertNotNull($retryAfter);
        self::assertGreaterThan(0, (int) $retryAfter);
    }

    #[Test]
    public function publicEndpointStreamsCacheOnlyAndNeverGenerates(): void
    {
        $admin = $this->authenticatedClient();

        // Never-regenerated feed: pulls 404 and no FeedRun ever appears.
        $coldFeed = $this->createFeed($admin, 'cold_feed');
        $coldUrl = $this->mintPullUrl($admin, $coldFeed);
        $anon = static::createClient();
        self::assertSame(404, $anon->request('GET', $coldUrl)->getStatusCode());
        self::assertSame(404, $anon->request('GET', $coldUrl)->getStatusCode());
        $coldRuns = $admin->request('GET', '/api/feeds/'.$coldFeed.'/runs')->toArray(false)['items'];
        self::assertIsArray($coldRuns);
        self::assertCount(0, $coldRuns);

        // Cached feed: pulls serve but the run count stays at the single
        // explicit regeneration — a pull never triggers generation.
        $warmFeed = $this->createFeed($admin, 'warm_feed');
        self::assertSame(202, $admin->request('POST', '/api/feeds/'.$warmFeed.'/regenerate')->getStatusCode());
        $warmUrl = $this->mintPullUrl($admin, $warmFeed);
        self::assertSame(200, $anon->request('GET', $warmUrl)->getStatusCode());
        self::assertSame(200, $anon->request('GET', $warmUrl)->getStatusCode());
        $warmRuns = $admin->request('GET', '/api/feeds/'.$warmFeed.'/runs')->toArray(false)['items'];
        self::assertIsArray($warmRuns);
        self::assertCount(1, $warmRuns);
    }

    #[Test]
    public function rotateKillsTheOldTokenAndRevokeKillsAll(): void
    {
        $admin = $this->authenticatedClient();
        $feedId = $this->createFeed($admin, 'lifecycle_feed');
        self::assertSame(202, $admin->request('POST', '/api/feeds/'.$feedId.'/regenerate')->getStatusCode());

        $tenantId = $this->demoTenant()->getId()->toRfc4122();
        $anon = static::createClient();

        $old = $this->mintToken($admin, $feedId);
        self::assertSame(200, $anon->request('GET', '/api/feeds/pull/'.$tenantId.'/'.$old.'.xml')->getStatusCode());

        $fresh = $this->mintToken($admin, $feedId); // rotate
        self::assertSame(404, $anon->request('GET', '/api/feeds/pull/'.$tenantId.'/'.$old.'.xml')->getStatusCode());
        self::assertSame(200, $anon->request('GET', '/api/feeds/pull/'.$tenantId.'/'.$fresh.'.xml')->getStatusCode());

        self::assertSame(204, $admin->request('DELETE', '/api/feeds/'.$feedId.'/token')->getStatusCode());
        self::assertSame(404, $anon->request('GET', '/api/feeds/pull/'.$tenantId.'/'.$fresh.'.xml')->getStatusCode());
    }

    #[Test]
    public function tokenHashNeverAppearsInApiResponses(): void
    {
        $admin = $this->authenticatedClient();
        $feedId = $this->createFeed($admin, 'hash_feed');
        $token = $this->mintToken($admin, $feedId);

        $detailRaw = $admin->request('GET', '/api/feeds/'.$feedId)->getContent(false);
        self::assertStringNotContainsString('token_hash', $detailRaw);
        self::assertStringNotContainsString($token, $detailRaw, 'the plaintext token is shown only at mint');
        $detail = json_decode($detailRaw, true);
        self::assertIsArray($detail);
        self::assertTrue($detail['has_token'] ?? false);

        $listRaw = $admin->request('GET', '/api/feeds')->getContent(false);
        self::assertStringNotContainsString('token_hash', $listRaw);
        self::assertStringNotContainsString($token, $listRaw);
    }

    /**
     * Seed a second tenant with one product, one generated feed and a minted
     * token — bypassing the API on purpose (no user/session exists for it).
     *
     * @return array{0: Tenant, 1: string} the tenant and its public pull URL
     */
    private function seedForeignTenantWithFeed(string $sku): array
    {
        $em = $this->em();
        $tenantContext = self::getContainer()->get(TenantContext::class);

        $tenantB = new Tenant('beta', 'Beta Tenant');
        $em->persist($tenantB);
        $em->flush();
        self::getContainer()->get(BuiltInObjectTypeSeeder::class)->seed($tenantB);

        $tenantContext->set($tenantB);
        $productType = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenantB);
        self::assertNotNull($productType);

        $em->persist(new CatalogObject($productType, $sku));

        $feedB = new FeedProfile(
            code: 'beta_feed',
            name: 'Beta feed',
            templateKind: FeedTemplateKind::Custom,
            objectTypeId: $productType->getId(),
            descriptor: $this->customDescriptor(),
            fieldMappings: [['slot' => 'sku', 'source' => ['kind' => 'attribute', 'ref' => 'sku']]],
            locale: 'pl',
        );
        $em->persist($feedB);
        $token = self::getContainer()->get(FeedTokenService::class)->mint($feedB);
        $em->flush();

        $run = new FeedRun($feedB->getId(), FeedRunTrigger::Manual);
        $em->persist($run);
        $em->flush();
        $runId = $run->getId();

        // The connection's RLS GUC still points at whichever tenant the last
        // HTTP request authenticated (demo, from createFeed) — rebind it to
        // tenant B before the in-band dispatch, exactly like the pull
        // controller's scopeToTenant, or the handler's repository reads see
        // an empty TenantFilter∩RLS intersection and silently skip the run.
        // tenant-safe: test scaffolding — establishes tenant B's own RLS scope for its seed
        $em->getConnection()->executeStatement(
            "SELECT set_config('app.current_tenant', :tenant, false)",
            ['tenant' => $tenantB->getId()->toRfc4122()],
        );
        // Sync transport in the test env: the handler generates the cache inline.
        self::getContainer()->get(MessageBusInterface::class)
            ->dispatch(new RunFeedMessage($runId, $tenantB->getId()));

        // Fail here (with the run status) rather than as an opaque 404 later.
        $finished = $this->em()->getRepository(FeedRun::class)->find($runId);
        self::assertInstanceOf(FeedRun::class, $finished);
        self::assertSame('done', $finished->getStatus()->value, 'tenant B feed generation must complete: '.($finished->getErrorMessage() ?? ''));

        // An anonymous puller on a live worker starts with NO tenant context
        // (the request listener disables the Doctrine tenant filter; RLS + the
        // URL tenant do the scoping). The shared test kernel keeps whatever
        // the last authenticated request configured — clear it so the public
        // pulls below run exactly like production.
        $tenantContext->clear();
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        return [$tenantB, '/api/feeds/pull/'.$tenantB->getId()->toRfc4122().'/'.$token.'.xml'];
    }

    private function seedProduct(Tenant $tenant, string $sku): void
    {
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $productType = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        self::assertNotNull($productType);

        $em = $this->em();
        $em->persist(new CatalogObject($productType, $sku));
        $em->flush();
    }

    private function demoTenant(): Tenant
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }

    private function createFeed(Client $client, string $code): string
    {
        $created = $client->request('POST', '/api/feeds', ['json' => [
            'template_kind' => 'custom',
            'code' => $code,
            'name' => 'Security probe feed',
            'object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
            'descriptor' => $this->customDescriptor(),
            'field_mappings' => [['slot' => 'sku', 'source' => ['kind' => 'attribute', 'ref' => 'sku']]],
            'locale' => 'pl',
        ]]);
        self::assertSame(201, $created->getStatusCode());

        $id = $created->toArray(false)['id'];
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function customDescriptor(): array
    {
        return [
            'root' => ['element' => 'products'],
            'item' => [
                'element' => 'product',
                'slots' => [['slot' => 'sku', 'node' => 'element', 'required' => true, 'fmt' => 'text']],
            ],
        ];
    }

    private function mintToken(Client $client, string $feedId): string
    {
        $minted = $client->request('POST', '/api/feeds/'.$feedId.'/token');
        self::assertSame(201, $minted->getStatusCode());

        $token = $minted->toArray(false)['token'];
        self::assertIsString($token);

        return $token;
    }

    private function mintPullUrl(Client $client, string $feedId): string
    {
        $minted = $client->request('POST', '/api/feeds/'.$feedId.'/token');
        self::assertSame(201, $minted->getStatusCode());

        $url = $minted->toArray(false)['url'];
        self::assertIsString($url);

        return $url;
    }

    private function tokenFromUrl(string $url): string
    {
        if (1 !== preg_match('#/(?<token>[A-Za-z0-9_-]+)\.xml$#', $url, $matches)) {
            self::fail('Pull URL does not carry a token: '.$url);
        }

        return $matches['token'];
    }
}
