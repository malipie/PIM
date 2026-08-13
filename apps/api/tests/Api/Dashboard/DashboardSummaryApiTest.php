<?php

declare(strict_types=1);

namespace App\Tests\Api\Dashboard;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Channel\Domain\Entity\Channel;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Entity\UserRole;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * DASH-04 (#2255, ADR-0026) — GET /api/dashboard/summary aggregates the
 * REAL objects table against Postgres: totals, cumulative completeness
 * buckets, publish-ready share, avg, the created-in-30d delta and the
 * per-channel aggregate; permission-gated and tenant-isolated.
 */
final class DashboardSummaryApiTest extends CatalogApiTestCase
{
    #[Test]
    public function summaryAggregatesKpisBucketsAndChannels(): void
    {
        $tenant = $this->demoTenant();
        $this->seedProducts($tenant, [
            ['sku' => 'P-100', 'pct' => 100, 'per_channel' => ['shopify' => 100]],
            ['sku' => 'P-060', 'pct' => 60, 'per_channel' => ['shopify' => 40]],
            ['sku' => 'P-030', 'pct' => 30, 'per_channel' => []],
        ]);
        $this->em()->persist(new Channel('shopify', 'Shopify'));
        $this->em()->flush();

        // Backdate one product beyond the 30-day window — the products
        // delta must count only the two recent ones.
        $this->em()->getConnection()->executeStatement(
            "UPDATE objects SET created_at = NOW() - INTERVAL '40 days' WHERE code = 'P-030'",
        );
        $this->em()->clear();

        $response = $this->authenticatedClient()->request('GET', '/api/dashboard/summary');

        self::assertResponseIsSuccessful();
        $body = $response->toArray();

        self::assertSame(['total' => 3, 'delta30d' => 2], $body['products']);
        self::assertSame(['count' => 1, 'pct' => 33, 'delta30d' => null], $body['publishReady']);
        self::assertSame(
            ['pct' => 63, 'delta30d' => null, 'weeklyDeltaPoints' => null],
            $body['avgCompleteness'],
            '(100+60+30)/3 rounded',
        );
        self::assertSame(
            [
                ['gte' => 25, 'count' => 3],
                ['gte' => 50, 'count' => 2],
                ['gte' => 80, 'count' => 1],
                ['gte' => 100, 'count' => 1],
            ],
            $body['buckets'],
        );
        self::assertSame(
            [['code' => 'shopify', 'name' => 'Shopify', 'avgPct' => 70, 'readyCount' => 1]],
            $body['channels'],
        );
        // DASH-09 — live open-alert count (nothing seeded here → 0).
        self::assertSame(['count' => 0], $body['openAlerts']);
    }

    #[Test]
    public function snapshotsAtTheHorizonsUnlockHonestDeltas(): void
    {
        $tenant = $this->demoTenant();
        $this->seedProducts($tenant, [
            ['sku' => 'P-100', 'pct' => 100, 'per_channel' => []],
            ['sku' => 'P-060', 'pct' => 60, 'per_channel' => []],
            ['sku' => 'P-030', 'pct' => 30, 'per_channel' => []],
        ]);

        // DASH-05: snapshots exactly at the 30d and 7d horizons.
        $this->em()->getConnection()->executeStatement(
            'INSERT INTO dashboard_snapshots '
            .'(id, tenant_id, snapshot_date, products_total, publish_ready_count, avg_completeness_pct, per_channel, created_at) VALUES '
            ."(:id30, :tenant, CURRENT_DATE - 30, 2, 0, 40, '{}', NOW()), "
            ."(:id7, :tenant, CURRENT_DATE - 7, 3, 1, 55, '{}', NOW())",
            [
                'id30' => '01980000-0000-7000-8000-000000000030',
                'id7' => '01980000-0000-7000-8000-000000000007',
                'tenant' => $tenant->getId()->toRfc4122(),
            ],
        );

        $response = $this->authenticatedClient()->request('GET', '/api/dashboard/summary');

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        // Current: ready=1, avg=63. Horizon 30d: ready 0, avg 40; 7d: avg 55.
        self::assertSame(['count' => 1, 'pct' => 33, 'delta30d' => 1], $body['publishReady']);
        self::assertSame(
            ['pct' => 63, 'delta30d' => 23, 'weeklyDeltaPoints' => 8],
            $body['avgCompleteness'],
        );
    }

    #[Test]
    public function emptyCatalogYieldsZeroesNotDivisionErrors(): void
    {
        $response = $this->authenticatedClient()->request('GET', '/api/dashboard/summary');

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertSame(['total' => 0, 'delta30d' => 0], $body['products']);
        self::assertSame(['count' => 0, 'pct' => 0, 'delta30d' => null], $body['publishReady']);
        self::assertSame([], $body['channels']);
    }

    #[Test]
    public function anonymousRequestIsRejected(): void
    {
        static::createClient()->request('GET', '/api/dashboard/summary');

        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function userWithoutProductsViewIsForbidden(): void
    {
        $tenant = $this->demoTenant();
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stub = new User($tenant, 'norole@demo.localhost', '', ['ROLE_USER']);
        $noRole = new User(
            $tenant,
            'norole@demo.localhost',
            $hasher->hashPassword($stub, 'changeme'),
            ['ROLE_USER'],
        );
        $this->em()->persist($noRole);
        $this->em()->flush();

        $this->authenticatedClient('norole@demo.localhost')->request('GET', '/api/dashboard/summary');

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function foreignTenantObjectsNeverLeakIntoTheNumbers(): void
    {
        $tenant = $this->demoTenant();
        $this->seedProducts($tenant, [['sku' => 'MINE-1', 'pct' => 100, 'per_channel' => []]]);
        $this->em()->flush();

        $beta = new Tenant('beta', 'Beta Tenant');
        $this->em()->persist($beta);
        $this->em()->flush();
        self::getContainer()->get(\App\Catalog\Application\BuiltInObjectTypeSeeder::class)->seed($beta);
        $this->seedProducts($beta, [
            ['sku' => 'BETA-1', 'pct' => 0, 'per_channel' => []],
            ['sku' => 'BETA-2', 'pct' => 0, 'per_channel' => []],
        ]);
        $this->em()->flush();
        $this->em()->clear();

        $response = $this->authenticatedClient()->request('GET', '/api/dashboard/summary');

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertSame(3, $this->demoTenantAgnosticTotal(), 'sanity: both tenants hold objects');
        self::assertSame(['total' => 1, 'delta30d' => 1], $body['products']);
        self::assertSame(
            ['pct' => 100, 'delta30d' => null, 'weeklyDeltaPoints' => null],
            $body['avgCompleteness'],
            'foreign tenant zeros must not drag the average',
        );
    }

    private function demoTenant(): Tenant
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }

    /**
     * #2831 — `products.view` opens the dashboard, but the per-channel
     * breakdown is channel data. A Catalog Manager holds the former and not
     * the latter, and used to receive the channel rows anyway.
     */
    #[Test]
    public function channelBreakdownIsOmittedForCallersWithoutChannelRead(): void
    {
        $tenant = $this->demoTenant();
        $this->seedProducts($tenant, [
            ['sku' => 'P-100', 'pct' => 100, 'per_channel' => ['shopify' => 100]],
        ]);
        $this->em()->persist(new Channel('shopify', 'Shopify'));
        $this->em()->flush();

        $this->createUserWithRole('catalog@demo.localhost', 'catalog_manager', $tenant);

        $response = $this->authenticatedClient('catalog@demo.localhost')
            ->request('GET', '/api/dashboard/summary');

        self::assertResponseIsSuccessful();
        $body = $response->toArray();

        self::assertSame([], $body['channels'], 'channel rows must not reach a caller without channel.read');
        // The rest of the payload is unaffected — the role does hold
        // products.view. Asserted whole (not `$body['products']['total']`)
        // because the nested offset is `mixed` under PHPStan max.
        self::assertSame(['total' => 1, 'delta30d' => 1], $body['products']);

        // Control: the admin (channel.read included) still gets the rows.
        $adminBody = $this->authenticatedClient()->request('GET', '/api/dashboard/summary')->toArray();
        self::assertSame(
            [['code' => 'shopify', 'name' => 'Shopify', 'avgPct' => 100, 'readyCount' => 1]],
            $adminBody['channels'],
        );
    }

    /**
     * Creates a user carrying exactly one PRD role, via the RBAC assignment
     * table the invitation flow uses.
     */
    private function createUserWithRole(string $email, string $roleCode, Tenant $tenant): void
    {
        $role = self::getContainer()->get(RoleRepositoryInterface::class)->findByCode($roleCode, $tenant);
        \assert(null !== $role);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stub = new User($tenant, $email, '', ['ROLE_USER']);
        $user = new User($tenant, $email, $hasher->hashPassword($stub, 'changeme'), ['ROLE_USER']);
        $this->em()->persist($user);
        $this->em()->flush();

        $this->em()->persist(new UserRole(
            userId: $user->getId(),
            roleId: $role->getId(),
        ));
        $this->em()->flush();
        $this->em()->clear();
    }

    /**
     * @param list<array{sku: string, pct: int, per_channel: array<string, int>}> $products
     */
    private function seedProducts(Tenant $tenant, array $products): void
    {
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $type);

        foreach ($products as $spec) {
            $object = new CatalogObject($type, $spec['sku']);
            $completeness = ['global' => $spec['pct']];
            if ([] !== $spec['per_channel']) {
                $completeness['per_channel'] = $spec['per_channel'];
            }
            $object->recordCompleteness($completeness);
            $this->em()->persist($object);
        }
        $this->em()->flush();
    }

    private function demoTenantAgnosticTotal(): int
    {
        $count = $this->em()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM objects WHERE kind = 'product'",
        );

        return (int) (\is_scalar($count) ? $count : 0);
    }
}
