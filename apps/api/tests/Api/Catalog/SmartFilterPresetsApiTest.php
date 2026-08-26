<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Application\BuiltInSmartFilterPresetsSeeder;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * VIEW-09 (#535) — SmartFilterPreset CRUD endpoints.
 *
 * Coverage:
 *   - 401 anonymous denied.
 *   - GET lists 5 system-shipped built-ins after migration.
 *   - POST creates a user-defined preset with auto-generated slug.
 *   - POST 400 on malformed name / invalid query DSL.
 *   - PATCH updates owner's preset, 403 on built-in, 404 on random UUID.
 *   - DELETE removes owner's preset, 403 on built-in.
 *   - Multi-tenant isolation: tenant B sees only built-ins + own user-defined.
 *   - `?counts=1` reports real matches (#3034).
 */
final class SmartFilterPresetsApiTest extends CatalogApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // doctrine:schema:create skips migration seed; replay built-ins
        // using a direct EntityManager instance to bypass test-container
        // service inlining (Symfony optimisation when no referrer).
        new BuiltInSmartFilterPresetsSeeder($this->em())->seed();
    }

    #[Test]
    public function anonymousRequestIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/smart-filter-presets');
        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function listReturnsFiveBuiltInPresets(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request('GET', '/api/smart-filter-presets');
        self::assertResponseStatusCodeSame(200);

        $body = $response->toArray();
        self::assertArrayHasKey('data', $body);
        $data = $body['data'];
        \assert(\is_array($data));

        $slugs = [];
        foreach ($data as $row) {
            \assert(\is_array($row));
            $slug = $row['slug'] ?? null;
            \assert(\is_string($slug));
            $slugs[] = $slug;
        }

        self::assertContains('inconsistent-translations', $slugs);
        self::assertContains('missing-images', $slugs);
        self::assertContains('weak-seo', $slugs);
        self::assertContains('red-low-completeness', $slugs);
        self::assertContains('no-category', $slugs);

        foreach ($data as $row) {
            self::assertIsArray($row);
            if ('red-low-completeness' === ($row['slug'] ?? null)) {
                self::assertTrue($row['is_built_in']);
                self::assertTrue($row['is_system']);
                self::assertSame('🔴', $row['icon']);
                self::assertEqualsCanonicalizing(['pl' => 'Czerwone (<50%)', 'en' => 'Red (<50%)'], $row['name']);
            }
        }
    }

    #[Test]
    public function createUserDefinedPresetAutoSlug(): void
    {
        $client = $this->authenticatedClient();

        $response = $client->request('POST', '/api/smart-filter-presets', [
            'json' => [
                'name' => ['pl' => 'Festo niski stock', 'en' => 'Festo low stock'],
                'icon' => '⚙️',
                'query' => ['attr' => 'brand', 'op' => '=', 'value' => 'Festo'],
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $body = $response->toArray();

        self::assertFalse($body['is_built_in']);
        self::assertFalse($body['is_system']);
        self::assertSame('festo-niski-stock', $body['slug']);
        self::assertSame(['pl' => 'Festo niski stock', 'en' => 'Festo low stock'], $body['name']);
    }

    #[Test]
    public function createColumnOnlyPresetWithEmptyFilter(): void
    {
        $client = $this->authenticatedClient();

        // PTR-01 — a preset can carry only a column layout with no filter
        // conditions (an empty AND group); the columns snapshot round-trips.
        $response = $client->request('POST', '/api/smart-filter-presets', [
            'json' => [
                'name' => ['pl' => 'Uklad kolumn', 'en' => 'Column layout'],
                'icon' => '📊',
                'query' => ['operator' => 'AND', 'conditions' => []],
                'columns' => [
                    ['key' => 'sku', 'position' => 0],
                    ['key' => 'name', 'hidden' => false, 'width' => 240, 'position' => 1],
                ],
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $body = $response->toArray();

        self::assertFalse($body['is_built_in']);
        self::assertSame(['operator' => 'AND', 'conditions' => []], $body['query']);
        self::assertSame([
            ['key' => 'sku', 'position' => 0],
            ['key' => 'name', 'hidden' => false, 'width' => 240, 'position' => 1],
        ], $body['columns']);
    }

    #[Test]
    public function resourceScopedPresetAppearsOnlyForItsResource(): void
    {
        $client = $this->authenticatedClient();

        // #1145 regression: the FE must send `resource` on create. Without
        // it the backend defaults to 'products', so a preset created from a
        // custom-object list (e.g. `samochody`) was filtered out of that
        // list's `?resource=samochody` fetch and never appeared.
        $client->request('POST', '/api/smart-filter-presets', [
            'json' => [
                'name' => ['pl' => 'Samochody nowe', 'en' => 'New cars'],
                'icon' => '🚗',
                'query' => ['attr' => 'status', 'op' => '=', 'value' => 'new'],
                'resource' => 'samochody',
            ],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // Visible when listing that resource (next to cross-kind NULL presets).
        $forSamochody = $client->request('GET', '/api/smart-filter-presets?resource=samochody')->toArray();
        self::assertContains('samochody-nowe', $this->slugsOf($forSamochody));

        // Hidden when listing a different resource.
        $forProducts = $client->request('GET', '/api/smart-filter-presets?resource=products')->toArray();
        self::assertNotContains('samochody-nowe', $this->slugsOf($forProducts));
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return list<string>
     */
    private function slugsOf(array $body): array
    {
        $data = $body['data'] ?? [];
        \assert(\is_array($data));

        $slugs = [];
        foreach ($data as $row) {
            \assert(\is_array($row));
            $slug = $row['slug'] ?? null;
            if (\is_string($slug)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    #[Test]
    public function createRejectsMalformedName(): void
    {
        $client = $this->authenticatedClient();
        $client->request('POST', '/api/smart-filter-presets', [
            'json' => [
                'name' => ['pl' => 'A', 'en' => 'B'], // too short (<3 chars)
                'icon' => '⚙️',
                'query' => ['attr' => 'brand', 'op' => '=', 'value' => 'Festo'],
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    #[Test]
    public function createRejectsInvalidQueryDsl(): void
    {
        $client = $this->authenticatedClient();
        $client->request('POST', '/api/smart-filter-presets', [
            'json' => [
                'name' => ['pl' => 'Niespójne brak', 'en' => 'Bad query'],
                'icon' => '⚠️',
                'query' => ['attr' => 'brand', 'op' => 'REGEX MATCH', 'value' => '^F.*$'],
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    #[Test]
    public function patchUpdatesOwnPreset(): void
    {
        $client = $this->authenticatedClient();
        $created = $client->request('POST', '/api/smart-filter-presets', [
            'json' => [
                'name' => ['pl' => 'Test preset', 'en' => 'Test preset'],
                'icon' => '🔧',
                'query' => ['attr' => 'brand', 'op' => '=', 'value' => 'Festo'],
            ],
        ])->toArray();

        $createdId = $created['id'];
        \assert(\is_string($createdId));

        $response = $client->request('PATCH', '/api/smart-filter-presets/'.$createdId, [
            'json' => [
                'name' => ['pl' => 'Zaktualizowany', 'en' => 'Updated preset'],
                'icon' => '⚡',
            ],
        ]);

        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();

        self::assertSame(['pl' => 'Zaktualizowany', 'en' => 'Updated preset'], $body['name']);
        self::assertSame('⚡', $body['icon']);
    }

    #[Test]
    public function patchBuiltInPresetForbidden(): void
    {
        $client = $this->authenticatedClient();
        $body = $client->request('GET', '/api/smart-filter-presets')->toArray();
        $data = $body['data'];
        \assert(\is_array($data));

        $redId = null;
        foreach ($data as $row) {
            \assert(\is_array($row));
            if ('red-low-completeness' === ($row['slug'] ?? null)) {
                $rowId = $row['id'] ?? null;
                \assert(\is_string($rowId));
                $redId = $rowId;
                break;
            }
        }
        self::assertNotNull($redId);

        $client->request('PATCH', '/api/smart-filter-presets/'.$redId, [
            'json' => ['icon' => '🟢'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function deleteBuiltInPresetForbidden(): void
    {
        $client = $this->authenticatedClient();
        $body = $client->request('GET', '/api/smart-filter-presets')->toArray();
        $data = $body['data'];
        \assert(\is_array($data) && [] !== $data);
        $first = $data[0];
        \assert(\is_array($first));
        $presetId = $first['id'] ?? null;
        \assert(\is_string($presetId));

        $client->request('DELETE', '/api/smart-filter-presets/'.$presetId);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function deleteOwnPresetReturnsNoContent(): void
    {
        $client = $this->authenticatedClient();
        $created = $client->request('POST', '/api/smart-filter-presets', [
            'json' => [
                'name' => ['pl' => 'Do skasowania', 'en' => 'To delete'],
                'icon' => '🗑️',
                'query' => ['attr' => 'brand', 'op' => '=', 'value' => 'Festo'],
            ],
        ])->toArray();
        $createdId = $created['id'];
        \assert(\is_string($createdId));

        $client->request('DELETE', '/api/smart-filter-presets/'.$createdId);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $body = $client->request('GET', '/api/smart-filter-presets')->toArray();
        $data = $body['data'];
        \assert(\is_array($data));
        $slugs = [];
        foreach ($data as $row) {
            \assert(\is_array($row));
            $slug = $row['slug'] ?? null;
            \assert(\is_string($slug));
            $slugs[] = $slug;
        }
        self::assertNotContains('do-skasowania', $slugs);
    }

    #[Test]
    public function patchReturnsNotFoundForRandomUuid(): void
    {
        $client = $this->authenticatedClient();
        $client->request('PATCH', '/api/smart-filter-presets/01234567-0123-7000-8000-000000000999', [
            'json' => ['icon' => '🟢'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function multiTenantIsolationHidesOtherTenantsUserPresets(): void
    {
        // Tenant A: admin creates a user-defined preset.
        $clientA = $this->authenticatedClient();
        $clientA->request('POST', '/api/smart-filter-presets', [
            'json' => [
                'name' => ['pl' => 'Tylko tenant A', 'en' => 'Tenant A only'],
                'icon' => '🅰️',
                'query' => ['attr' => 'brand', 'op' => '=', 'value' => 'Festo'],
            ],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // Tenant B: seed a second tenant + user, sign JWT, verify isolation.
        $em = $this->em();
        $tenantB = new Tenant('demo-b', 'Demo Tenant B');
        $em->persist($tenantB);
        $em->flush();

        // RBAC-P6 retrofit — seed PRD roles + assign tenant_owner so admin-b
        // can hit /api/smart-filter-presets (products.view permission). Same
        // pattern as CatalogApiTestCase::setUp for the primary admin.
        self::getContainer()->get(\App\Identity\Application\SeedTenantPrdRolesService::class)->seed($tenantB);
        $tenantBOwner = self::getContainer()->get(RoleRepositoryInterface::class)
            ->findByCode('tenant_owner', $tenantB);
        \assert(null !== $tenantBOwner);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stubB = new User($tenantB, 'admin-b@demo.localhost', '', ['ROLE_USER']);
        $adminB = new User($tenantB, 'admin-b@demo.localhost', $hasher->hashPassword($stubB, 'changeme'), ['ROLE_USER']);
        $adminB->addRole($tenantBOwner);
        $em->persist($adminB);
        $em->flush();

        $userBRepo = self::getContainer()->get(UserRepositoryInterface::class);
        $userB = $userBRepo->findByEmail('admin-b@demo.localhost');
        self::assertNotNull($userB);
        $jwtManager = self::getContainer()->get(JWTTokenManagerInterface::class);
        $jwtB = $jwtManager->create($userB);

        $clientB = static::createClient();
        $clientB->setDefaultOptions(['headers' => ['authorization' => 'Bearer '.$jwtB]]);

        $bodyB = $clientB->request('GET', '/api/smart-filter-presets')->toArray();
        $data = $bodyB['data'];
        \assert(\is_array($data));
        $slugsB = [];
        foreach ($data as $row) {
            \assert(\is_array($row));
            $slug = $row['slug'] ?? null;
            \assert(\is_string($slug));
            $slugsB[] = $slug;
        }

        self::assertContains('red-low-completeness', $slugsB);
        self::assertNotContains('tylko-tenant-a', $slugsB);
    }

    /**
     * #3034 — `?counts=1` used to query `catalog_objects`, a table that does
     * not exist, and swallow the resulting error into a 0. Every preset chip
     * in production therefore read "0". Nothing covered this path, which is
     * why it went unnoticed; this test is that cover.
     *
     * `missing-images` matches any product without `main_image.asset_id`, so
     * two bare products must be counted as two.
     */
    #[Test]
    public function countsReportRealMatchesRatherThanZero(): void
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $product = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $product);

        foreach (['SKU-COUNT-1', 'SKU-COUNT-2'] as $code) {
            $this->em()->persist(new CatalogObject($product, $code));
        }
        $this->em()->flush();
        self::getContainer()->get(TenantContext::class)->clear();

        $body = $this->authenticatedClient()->request('GET', '/api/smart-filter-presets?counts=1')->toArray();
        $data = $body['data'];
        \assert(\is_array($data));

        $counts = [];
        foreach ($data as $row) {
            \assert(\is_array($row));
            $slug = $row['slug'] ?? null;
            \assert(\is_string($slug));
            $counts[$slug] = $row['count'] ?? null;
        }

        self::assertArrayHasKey('missing-images', $counts);
        self::assertSame(2, $counts['missing-images']);
    }
}
