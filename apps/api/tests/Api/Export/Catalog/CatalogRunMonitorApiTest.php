<?php

declare(strict_types=1);

namespace App\Tests\Api\Export\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Export\Catalog\Domain\Entity\CatalogProfile;
use App\Export\Catalog\Domain\Enum\CatalogTemplateKind;
use App\Export\Catalog\Domain\Repository\CatalogProfileRepositoryInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * CPDF-P3-03 — catalog generate dispatch + run monitor + KPI endpoints.
 *
 * Mirrors the Feed monitor coverage: the manual "Generuj teraz" trigger
 * (202 create + dispatch), the tenant-wide run list (keyset), the 24h KPI
 * tiles and the per-catalog run history, plus the RBAC boundary cases
 * (`integration.admin` on generate/cancel, `exports.view_all` on reads,
 * 401 anonymous, 404 unknown id).
 *
 * The `import` transport is sync:// under test, so `POST /generate` runs the
 * {@see \App\Export\Catalog\Application\Async\GenerateCatalogHandler} inline;
 * the fixtures below (Product type + sku/name/description attributes + two
 * objects with values + a matching CatalogProfile) let the render reach a real
 * `%PDF-` artifact, so the run terminates `done` and the monitor/KPI/history
 * endpoints have a genuine terminal run to report.
 */
final class CatalogRunMonitorApiTest extends CatalogApiTestCase
{
    private const string MARKETING_EMAIL = 'marketing-cpdf-monitor@demo.localhost';

    #[Test]
    public function generateDispatchesARunAndTheMonitorReportsIt(): void
    {
        $catalogId = $this->seedRenderableCatalog();
        $client = $this->authenticatedClient();

        // Manual "Generuj teraz" — sync transport renders inline, so the run
        // reaches a terminal state before the response is serialized.
        $generated = $client->request('POST', '/api/catalogs/'.$catalogId.'/generate');
        self::assertSame(202, $generated->getStatusCode());
        $body = $generated->toArray(false);
        self::assertIsArray($body['run']);
        $run = $body['run'];
        self::assertIsString($run['id']);
        $runId = $run['id'];
        self::assertSame($catalogId, $run['catalog_id']);
        self::assertIsString($body['mercure_topic']);
        self::assertStringContainsString('/catalogs/'.$catalogId.'/runs', $body['mercure_topic']);

        // Global run list — the fresh run is present.
        $list = $client->request('GET', '/api/catalog-runs');
        self::assertSame(200, $list->getStatusCode());
        $listBody = $list->toArray(false);
        self::assertIsArray($listBody['items']);
        self::assertNotEmpty($listBody['items']);
        self::assertContains($runId, array_column($listBody['items'], 'id'));
        self::assertArrayHasKey('next_cursor', $listBody);

        // KPI tiles — at least this one regeneration in the last 24h.
        $kpi = $client->request('GET', '/api/catalog-runs/kpi');
        self::assertSame(200, $kpi->getStatusCode());
        $kpiBody = $kpi->toArray(false);
        self::assertArrayHasKey('regenerations_24h', $kpiBody);
        self::assertGreaterThanOrEqual(1, $kpiBody['regenerations_24h']);
        self::assertArrayHasKey('errors_24h', $kpiBody);
        self::assertArrayHasKey('items_24h', $kpiBody);
        self::assertArrayHasKey('pages_published', $kpiBody);

        // Per-catalog run history — lists the run we just triggered.
        $history = $client->request('GET', '/api/catalogs/'.$catalogId.'/runs');
        self::assertSame(200, $history->getStatusCode());
        $historyBody = $history->toArray(false);
        self::assertIsArray($historyBody['items']);
        self::assertNotEmpty($historyBody['items']);
        self::assertContains($runId, array_column($historyBody['items'], 'id'));
    }

    #[Test]
    public function generateOnAnUnknownCatalogIsA404(): void
    {
        $client = $this->authenticatedClient();

        self::assertSame(
            404,
            $client->request('POST', '/api/catalogs/00000000-0000-7000-8000-000000000000/generate')->getStatusCode(),
        );
    }

    #[Test]
    public function aPersonaWithoutTheGrantsIsForbidden(): void
    {
        $catalogId = $this->seedRenderableCatalog();
        $marketingJwt = $this->seedMarketingUser();
        $client = static::createClient();

        // Marketing lacks integration.admin → generate refuses with 403.
        $client->request('POST', '/api/catalogs/'.$catalogId.'/generate', [
            'headers' => ['authorization' => 'Bearer '.$marketingJwt],
        ]);
        self::assertResponseStatusCodeSame(403);

        // Marketing lacks exports.view_all → monitor reads refuse with 403.
        $client->request('GET', '/api/catalog-runs', [
            'headers' => ['authorization' => 'Bearer '.$marketingJwt],
        ]);
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/api/catalog-runs/kpi', [
            'headers' => ['authorization' => 'Bearer '.$marketingJwt],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function anonymousRequestsAre401(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/catalog-runs');
        self::assertResponseStatusCodeSame(401);

        $client->request('GET', '/api/catalog-runs/kpi');
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Seeds sku/name/description attributes onto the demo tenant's built-in
     * Product ObjectType, two products with values and a CatalogProfile whose
     * field mappings match — so the sync generate renders a real PDF.
     */
    private function seedRenderableCatalog(): string
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        self::getContainer()->get(TenantContext::class)->set($tenant);

        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($type instanceof ObjectType);
        $typeId = $type->getId();

        $sku = new Attribute('sku', ['en' => 'SKU'], AttributeType::Text);
        $name = new Attribute('name', ['en' => 'Name'], AttributeType::Text);
        $description = new Attribute('description', ['en' => 'Description'], AttributeType::Wysiwyg);
        $em->persist($sku);
        $em->persist($name);
        $em->persist($description);
        $em->persist(new ObjectTypeAttribute($type, $sku, false, 1));
        $em->persist(new ObjectTypeAttribute($type, $name, false, 2));
        $em->persist(new ObjectTypeAttribute($type, $description, false, 3));
        $em->flush();

        $tenantId = $tenant->getId()->toRfc4122();
        $conn = $em->getConnection();

        $conn->executeStatement(
            <<<'SQL'
                INSERT INTO objects (id, tenant_id, object_type_id, kind, code, enabled, status, completeness, attributes_indexed, created_at, updated_at, completeness_pct, sync_status_aggregate, version, schema_drift)
                SELECT gen_random_uuid(), :t, :ot, 'product', 'CPDF-'||g, true, 'published', '{}'::jsonb, '{}'::jsonb, now(), now(), 0, 'gray', 1, false
                FROM generate_series(1, 2) g
                SQL,
            ['t' => $tenantId, 'ot' => $typeId->toRfc4122()],
        );

        foreach ([$sku->getId()->toRfc4122(), $name->getId()->toRfc4122()] as $attrId) {
            $conn->executeStatement(
                <<<'SQL'
                    INSERT INTO object_values (id, tenant_id, object_id, attribute_id, value, provenance, provenance_meta)
                    SELECT gen_random_uuid(), :t, o.id, :a, jsonb_build_object('value', o.code), 'import', '{}'::jsonb
                    FROM objects o WHERE o.tenant_id = :t
                    SQL,
                ['t' => $tenantId, 'a' => $attrId],
            );
        }
        $conn->executeStatement(
            <<<'SQL'
                INSERT INTO object_values (id, tenant_id, object_id, attribute_id, value, provenance, provenance_meta)
                SELECT gen_random_uuid(), :t, o.id, :a, jsonb_build_object('value', :d::text), 'import', '{}'::jsonb
                FROM objects o WHERE o.tenant_id = :t
                SQL,
            ['t' => $tenantId, 'a' => $description->getId()->toRfc4122(), 'd' => '<strong>Opis produktu</strong>'],
        );

        $profile = new CatalogProfile(
            'monitor-cat',
            'Monitor Catalog',
            CatalogTemplateKind::Sheet,
            $typeId,
            branding: ['color' => '#0ea5e9', 'company_name' => 'ACME'],
            fieldMappings: [
                ['slot' => 'title', 'source' => ['kind' => 'attribute', 'ref' => 'name']],
                ['slot' => 'sku', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
                ['slot' => 'description', 'source' => ['kind' => 'attribute', 'ref' => 'description']],
            ],
        );
        $profile->assignTenant($tenant);
        self::getContainer()->get(CatalogProfileRepositoryInterface::class)->save($profile);

        $em->clear();

        // Re-establish tenant scope + RLS GUC after the clear so the follow-up
        // API requests (which boot their own request scope) see the fixtures.
        $reloaded = $em->getRepository(Tenant::class)->find($tenant->getId());
        \assert($reloaded instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($reloaded);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        return $profile->getId()->toRfc4122();
    }

    private function seedMarketingUser(): string
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $marketing = self::getContainer()->get(RoleRepositoryInterface::class)
            ->findByCode('marketing', $tenant);
        \assert(null !== $marketing, 'marketing role must be seeded per tenant');

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stub = new User($tenant, self::MARKETING_EMAIL, '', ['ROLE_USER']);
        $user = new User($tenant, self::MARKETING_EMAIL, $hasher->hashPassword($stub, 'changeme'), ['ROLE_USER']);
        $user->addRole($marketing);
        $em->persist($user);
        $em->flush();

        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }
}
