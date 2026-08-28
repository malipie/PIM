<?php

declare(strict_types=1);

namespace App\Tests\Api\Export\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

use const JSON_THROW_ON_ERROR;

/**
 * CPDF-P4-01 — catalog HTML live-preview (POST /api/catalogs/preview draft +
 * GET /api/catalogs/{id}/preview saved). The preview renders the sheet template
 * Twig HTML for a few sample products in-memory (no PDF, no CatalogRun, no
 * cache), so the wizard can show it in an iframe.
 *
 * Mirrors {@see CatalogProfileControllerApiTest}: shares `exports.view_all` and
 * the marketing-persona 403 boundary. Seeds the demo tenant's Product
 * ObjectType with sku/name/description attributes + 2 objects so the render has
 * real values to bind.
 */
final class CatalogPreviewApiTest extends CatalogApiTestCase
{
    private const string MARKETING_EMAIL = 'marketing-cpdf-preview@demo.localhost';

    #[Test]
    public function draftPreviewRendersSampleHtml(): void
    {
        $this->seedProductsWithValues('<strong>Solidny opis</strong>');
        $client = $this->authenticatedClient();

        $response = $client->request('POST', '/api/catalogs/preview', ['json' => [
            'template_kind' => 'sheet',
            'object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
            'branding' => ['color' => '#0ea5e9'],
            'field_mappings' => [
                ['slot' => 'title', 'source' => ['kind' => 'attribute', 'ref' => 'name']],
                ['slot' => 'description', 'source' => ['kind' => 'attribute', 'ref' => 'description']],
            ],
        ]]);

        self::assertSame(200, $response->getStatusCode());
        $body = $response->toArray(false);

        $sampleCount = $body['sample_count'] ?? null;
        self::assertIsInt($sampleCount);
        self::assertGreaterThanOrEqual(1, $sampleCount);

        $html = $body['html'] ?? null;
        self::assertIsString($html);
        self::assertStringContainsString('#0ea5e9', $html, 'the brand colour reaches the rendered HTML');
        self::assertStringNotContainsString('<script', $html, 'the sanitiser strips scripts from rich-text slots');
    }

    #[Test]
    public function savedPreviewRendersFromStoredProfile(): void
    {
        $this->seedProductsWithValues('<strong>Opis</strong>');
        $client = $this->authenticatedClient();

        $created = $client->request('POST', '/api/catalogs', ['json' => [
            'template_kind' => 'sheet',
            'code' => 'preview_cat',
            'name' => 'Katalog podglądu',
            'object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
            'branding' => ['color' => '#16a34a'],
            'field_mappings' => [
                ['slot' => 'title', 'source' => ['kind' => 'attribute', 'ref' => 'name']],
                ['slot' => 'sku', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
            ],
        ]]);
        self::assertSame(201, $created->getStatusCode());
        $id = $created->toArray(false)['id'];
        self::assertIsString($id);

        $preview = $client->request('GET', '/api/catalogs/'.$id.'/preview');
        self::assertSame(200, $preview->getStatusCode());

        $body = $preview->toArray(false);
        $html = $body['html'] ?? null;
        self::assertIsString($html);
        self::assertStringContainsString('#16a34a', $html);
    }

    #[Test]
    public function templateKindSelectsDistinctSheetAndGridArchetypes(): void
    {
        $this->seedProductsWithValues('<strong>Opis</strong>');
        $client = $this->authenticatedClient();
        $base = [
            'object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
            'field_mappings' => [
                ['slot' => 'title', 'source' => ['kind' => 'attribute', 'ref' => 'name']],
                ['slot' => 'sku', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
            ],
            'limit' => 2,
        ];

        $sheet = $client->request('POST', '/api/catalogs/preview', [
            'json' => [...$base, 'template_kind' => 'sheet'],
        ])->toArray(false)['html'] ?? null;
        $grid = $client->request('POST', '/api/catalogs/preview', [
            'json' => [...$base, 'template_kind' => 'grid'],
        ])->toArray(false)['html'] ?? null;

        self::assertIsString($sheet);
        self::assertIsString($grid);
        self::assertStringContainsString('class="sheet', $sheet, 'sheet renders one product data-sheet block');
        self::assertStringNotContainsString('class="toc"', $sheet);
        self::assertStringContainsString('class="toc"', $grid, 'grid renders its table of contents');
        self::assertStringContainsString('class="grid"', $grid, 'grid renders the multi-card table');
        self::assertNotSame(hash('sha256', $sheet), hash('sha256', $grid));
    }

    #[Test]
    public function missingTemplateKindIsA400(): void
    {
        $client = $this->authenticatedClient();

        self::assertSame(400, $client->request('POST', '/api/catalogs/preview', ['json' => [
            'object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
        ]])->getStatusCode());
    }

    #[Test]
    public function unknownTemplateKindIsA400(): void
    {
        $client = $this->authenticatedClient();

        self::assertSame(400, $client->request('POST', '/api/catalogs/preview', ['json' => [
            'template_kind' => 'not_a_kind',
            'object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
        ]])->getStatusCode());
    }

    #[Test]
    public function missingObjectTypeIdIsA400(): void
    {
        $client = $this->authenticatedClient();

        self::assertSame(400, $client->request('POST', '/api/catalogs/preview', ['json' => [
            'template_kind' => 'sheet',
        ]])->getStatusCode());
    }

    #[Test]
    public function aPersonaWithoutTheExportGrantIsForbidden(): void
    {
        $marketingJwt = $this->seedMarketingUser();
        $client = static::createClient();

        $client->request('POST', '/api/catalogs/preview', [
            'headers' => ['authorization' => 'Bearer '.$marketingJwt, 'content-type' => 'application/json'],
            'body' => json_encode([
                'template_kind' => 'sheet',
                'object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function anonymousRequestsAre401(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/catalogs/preview', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['template_kind' => 'sheet'], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Seeds sku/name/description attributes onto the demo tenant's built-in
     * Product ObjectType plus two products with values, mirroring
     * {@see \App\Tests\Integration\Export\Catalog\CatalogRenderServiceTest}.
     */
    private function seedProductsWithValues(string $descriptionValue): void
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
            ['t' => $tenantId, 'a' => $description->getId()->toRfc4122(), 'd' => $descriptionValue],
        );

        $em->clear();

        // Re-establish tenant scope + RLS GUC after the clear so the follow-up
        // API requests (which boot their own request scope) see the fixtures.
        $reloaded = $em->getRepository(Tenant::class)->find($tenant->getId());
        \assert($reloaded instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($reloaded);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();
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
