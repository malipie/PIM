<?php

declare(strict_types=1);

namespace App\Tests\Api\Export\Catalog;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Catalog\Domain\ObjectKind;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * CPDF-P3-04 — bulk catalog generation. One call fans out to one run per
 * requested catalog (Plytix parity); unknown/other-tenant ids are skipped,
 * gating is the same single-item integration.admin (no bulk escalation).
 */
final class CatalogBulkGenerateApiTest extends CatalogApiTestCase
{
    private const string MARKETING_EMAIL = 'marketing-cpdf-bulk@demo.localhost';

    #[Test]
    public function bulkGenerateDispatchesOneRunPerKnownCatalog(): void
    {
        $client = $this->authenticatedClient();
        $first = $this->createCatalog($client, 'bulk-a');
        $second = $this->createCatalog($client, 'bulk-b');
        $unknown = Uuid::v7()->toRfc4122();

        $response = $client->request('POST', '/api/catalogs/bulk-generate', [
            'json' => ['catalog_ids' => [$first, $second, $unknown]],
        ]);

        self::assertSame(202, $response->getStatusCode());
        $body = $response->toArray(false);
        self::assertIsArray($body['dispatched']);
        self::assertCount(2, $body['dispatched'], 'every known catalog gets one run');
        $dispatchedIds = array_column($body['dispatched'], 'catalog_id');
        self::assertContains($first, $dispatchedIds);
        self::assertContains($second, $dispatchedIds);
        self::assertSame([$unknown], $body['skipped'], 'the unknown catalog is skipped, not leaked');
    }

    #[Test]
    public function emptyPayloadIsA400(): void
    {
        $client = $this->authenticatedClient();

        self::assertSame(400, $client->request('POST', '/api/catalogs/bulk-generate', ['json' => []])->getStatusCode());
        self::assertSame(400, $client->request('POST', '/api/catalogs/bulk-generate', [
            'json' => ['catalog_ids' => []],
        ])->getStatusCode());
        self::assertSame(400, $client->request('POST', '/api/catalogs/bulk-generate', [
            'json' => ['catalog_ids' => ['not-a-uuid']],
        ])->getStatusCode());
    }

    #[Test]
    public function aPersonaWithoutIntegrationAdminIsForbidden(): void
    {
        $admin = $this->authenticatedClient();
        $catalogId = $this->createCatalog($admin, 'bulk-rbac');

        $marketingJwt = $this->seedMarketingUser();
        $client = static::createClient();
        $client->request('POST', '/api/catalogs/bulk-generate', [
            'headers' => ['authorization' => 'Bearer '.$marketingJwt],
            'json' => ['catalog_ids' => [$catalogId]],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function anonymousRequestIs401(): void
    {
        static::createClient()->request('POST', '/api/catalogs/bulk-generate', [
            'json' => ['catalog_ids' => [Uuid::v7()->toRfc4122()]],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    private function createCatalog(Client $client, string $code): string
    {
        $created = $client->request('POST', '/api/catalogs', [
            'json' => [
                'code' => $code,
                'name' => 'Bulk '.$code,
                'template_kind' => 'sheet',
                'object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                'field_mappings' => [],
            ],
        ]);
        self::assertSame(201, $created->getStatusCode());
        $id = $created->toArray(false)['id'];
        self::assertIsString($id);

        return $id;
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
