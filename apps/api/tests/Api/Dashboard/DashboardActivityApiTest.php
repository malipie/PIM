<?php

declare(strict_types=1);

namespace App\Tests\Api\Dashboard;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Identity\Domain\Entity\AuditLog;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Entity\UserRole;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;

/**
 * DASH-07 (#2261, ADR-0026) — the activity endpoints aggregate the REAL
 * objects + audit_logs tables against Postgres: gap-filled daily series,
 * route/action/permission narrowing, most-edited ranking with hydration
 * and over-fetch, input validation, RBAC and tenant isolation.
 */
final class DashboardActivityApiTest extends CatalogApiTestCase
{
    #[Test]
    public function activitySeriesIsGapFilledAndNarrowedToGrantedProductWrites(): void
    {
        $tenant = $this->demoTenant();
        $productId = $this->seedProduct($tenant, 'P-1');

        // Two products created today (P-1 above and P-2 here); nothing else
        // inside the 7d window.
        $this->seedProduct($tenant, 'P-2');

        // Granted product edits: 2 today + 1 two days ago…
        $this->seedAudit($tenant, 'PATCH', 'products_patch', $productId, 'granted', 'today');
        $this->seedAudit($tenant, 'PUT', 'pim_products_categories_replace', $productId, 'granted', 'today');
        $this->seedAudit($tenant, 'PATCH', 'objects_patch', $productId, 'granted', '-2 days');
        // …and noise that must NOT count: denied edit, schema route, read.
        $this->seedAudit($tenant, 'PATCH', 'products_patch', $productId, 'denied', 'today');
        $this->seedAudit($tenant, 'PATCH', 'pim_object_types_update', $productId, 'granted', 'today');
        $this->seedAudit($tenant, 'GET', 'products_get', $productId, 'granted', 'today');

        $response = $this->authenticatedClient()->request('GET', '/api/dashboard/activity?range=7d');

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertSame('7d', $body['range']);
        self::assertIsArray($body['series']);
        self::assertCount(7, $body['series'], 'series is contiguous over the whole window');
        self::assertSame(2, $body['addedTotal']);
        self::assertSame(3, $body['modifiedTotal']);
        self::assertSame(1, $body['avgPerDay'], '(2+3)/7 rounded');

        $series = $body['series'];
        self::assertIsArray($series);
        $today = $series[6] ?? null;
        self::assertIsArray($today);
        self::assertSame(2, $today['added']);
        self::assertSame(2, $today['modified']);
        $twoDaysAgo = $series[4] ?? null;
        self::assertIsArray($twoDaysAgo);
        self::assertSame(0, $twoDaysAgo['added']);
        self::assertSame(1, $twoDaysAgo['modified']);
    }

    #[Test]
    public function topEditedRanksHydratesAndSkipsDeletedResources(): void
    {
        $tenant = $this->demoTenant();
        $p1 = $this->seedProduct($tenant, 'TOP-1', ['value' => 'Czujnik indukcyjny']);
        $p2 = $this->seedProduct($tenant, 'TOP-2');

        foreach ([1, 2, 3] as $i) {
            $this->seedAudit($tenant, 'PATCH', 'products_patch', $p1, 'granted', \sprintf('-%d hours', $i));
        }
        $this->seedAudit($tenant, 'PATCH', 'products_patch', $p2, 'granted', 'today');

        // A ghost resource with MORE edits than P-2 — hydration must skip
        // it (deleted product) and still return P-2 thanks to over-fetch.
        $ghost = Uuid::v7()->toRfc4122();
        $this->seedAudit($tenant, 'PATCH', 'products_patch', $ghost, 'granted', 'today');
        $this->seedAudit($tenant, 'PUT', 'pim_objects_relations_put', $ghost, 'granted', 'today');

        $response = $this->authenticatedClient()->request(
            'GET',
            '/api/dashboard/top-edited?range=30d&limit=2',
        );

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertSame(
            [
                [
                    'id' => $p1,
                    'name' => 'Czujnik indukcyjny',
                    'sku' => 'TOP-1',
                    'completenessPct' => 50,
                    'edits' => 3,
                ],
                [
                    'id' => $p2,
                    'name' => 'TOP-2',
                    'sku' => 'TOP-2',
                    'completenessPct' => 50,
                    'edits' => 1,
                ],
            ],
            $body['items'],
            'ranked by edits, ghost skipped, name falls back to the code',
        );
    }

    #[Test]
    public function inputValidationRejectsUnknownRangeAndLimit(): void
    {
        $client = $this->authenticatedClient();

        $client->request('GET', '/api/dashboard/activity?range=14d');
        self::assertResponseStatusCodeSame(400);

        $client->request('GET', '/api/dashboard/top-edited?limit=0');
        self::assertResponseStatusCodeSame(400);

        $client->request('GET', '/api/dashboard/top-edited?limit=21');
        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function anonymousAndUnprivilegedRequestsAreRejected(): void
    {
        static::createClient()->request('GET', '/api/dashboard/activity');
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * #2831 — /top-edited attributes edits to named people. It used to sit
     * behind `products.view`, which a Catalog Manager holds while its audit
     * reach is only `audit.view_own`, so the role could read who else had
     * been editing. The aggregate series stays open to the same role: it
     * counts catalog changes without naming anyone.
     */
    #[Test]
    public function topEditedIsRefusedWithoutCrossUserAuditReach(): void
    {
        $tenant = $this->demoTenant();
        $role = self::getContainer()->get(RoleRepositoryInterface::class)
            ->findByCode('catalog_manager', $tenant);
        \assert(null !== $role);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stub = new User($tenant, 'catalog@demo.localhost', '', ['ROLE_USER']);
        $user = new User(
            $tenant,
            'catalog@demo.localhost',
            $hasher->hashPassword($stub, 'changeme'),
            ['ROLE_USER'],
        );
        $this->em()->persist($user);
        $this->em()->flush();
        $this->em()->persist(new UserRole(userId: $user->getId(), roleId: $role->getId()));
        $this->em()->flush();
        $this->em()->clear();

        $client = $this->authenticatedClient('catalog@demo.localhost');

        $client->request('GET', '/api/dashboard/top-edited');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/api/dashboard/activity');
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function foreignTenantActivityNeverLeaksIntoTheNumbers(): void
    {
        $tenant = $this->demoTenant();
        $mine = $this->seedProduct($tenant, 'MINE-1');
        $this->seedAudit($tenant, 'PATCH', 'products_patch', $mine, 'granted', 'today');

        $beta = new Tenant('beta', 'Beta Tenant');
        $this->em()->persist($beta);
        $this->em()->flush();
        self::getContainer()->get(\App\Catalog\Application\BuiltInObjectTypeSeeder::class)->seed($beta);
        $betaProduct = $this->seedProduct($beta, 'BETA-1');
        foreach ([1, 2, 3, 4] as $i) {
            $this->seedAudit($beta, 'PATCH', 'products_patch', $betaProduct, 'granted', \sprintf('-%d hours', $i));
        }
        $this->em()->clear();

        $response = $this->authenticatedClient()->request('GET', '/api/dashboard/activity?range=7d');
        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertSame(1, $body['addedTotal']);
        self::assertSame(1, $body['modifiedTotal']);

        $top = $this->authenticatedClient()->request('GET', '/api/dashboard/top-edited?range=7d&limit=5');
        $items = $top->toArray()['items'];
        self::assertIsArray($items);
        self::assertCount(1, $items);
    }

    private function demoTenant(): Tenant
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }

    /**
     * @param array<string, mixed>|null $nameEnvelope value of the `name` key in attributes_indexed
     */
    private function seedProduct(Tenant $tenant, string $sku, ?array $nameEnvelope = null): string
    {
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $type);

        $object = new CatalogObject($type, $sku);
        $object->recordCompleteness(['global' => 50]);
        $this->em()->persist($object);
        $this->em()->flush();

        if (null !== $nameEnvelope) {
            // Raw write — the sync listener recomputes attributes_indexed
            // from ObjectValues on flush, wiping in-entity seeding.
            $this->em()->getConnection()->executeStatement(
                'UPDATE objects SET attributes_indexed = :idx WHERE id = :id',
                [
                    'idx' => json_encode(['name' => $nameEnvelope], JSON_THROW_ON_ERROR),
                    'id' => $object->getId()->toRfc4122(),
                ],
            );
        }

        return $object->getId()->toRfc4122();
    }

    private function seedAudit(
        Tenant $tenant,
        string $action,
        string $route,
        string $resourceId,
        string $permissionResult,
        string $when,
    ): void {
        $entry = new AuditLog(
            id: Uuid::v7(),
            tenantId: $tenant->getId(),
            userId: null,
            superAdminId: null,
            action: $action,
            resourceType: $route,
            resourceId: $resourceId,
            oldValue: null,
            newValue: null,
            permissionCheckResult: $permissionResult,
            crossTenantAccess: false,
            specialFlags: [],
            ipAddress: null,
            userAgent: null,
            createdAt: new DateTimeImmutable($when),
        );
        $this->em()->persist($entry);
        $this->em()->flush();
    }
}
