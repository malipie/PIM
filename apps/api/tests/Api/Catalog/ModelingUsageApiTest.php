<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Application\BuiltInSystemAttributesSeeder;
use App\Catalog\Application\Query\Usage\UsageQueryService;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\AttributeGroupAttribute;
use App\Catalog\Domain\Entity\AttributeOption;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\Entity\ObjectTypeAttributeGroup;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Provenance;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Identity\Domain\Entity\Role;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * #3034 — batch `where-used` endpoints for the modeling list pages.
 *
 * The load-bearing assertions here are the parity tests: the batch payload
 * must be byte-for-byte what the per-item endpoint returns, or the list and
 * the detail page of the same row would disagree. Both halves run against a
 * cleared cache, because the two code paths deliberately share cache keys —
 * without the clear, the second half would read what the first half wrote and
 * the SQL would never be compared at all.
 */
final class ModelingUsageApiTest extends CatalogApiTestCase
{
    private const string OTHER_TENANT_CODE = 'other-tenant';
    private const string LIMITED_EMAIL = 'catalog-manager@demo.localhost';
    private const string UNGRANTED_EMAIL = 'nobody@demo.localhost';

    private Attribute $material;
    private Attribute $size;
    private AttributeGroup $marketing;
    private ObjectType $product;
    private string $foreignAttributeId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        self::getContainer()->get(BuiltInSystemAttributesSeeder::class)->seed($tenant);

        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($tenant);

        $em = $this->em();
        $product = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($product instanceof ObjectType);
        $this->product = $product;

        $this->material = new Attribute('material', ['en' => 'Material'], AttributeType::Text);
        $em->persist($this->material);
        // A select attribute so `optionCount` has something to report.
        $this->size = new Attribute('size', ['en' => 'Size'], AttributeType::Select);
        $em->persist($this->size);
        $this->marketing = new AttributeGroup('marketing', ['en' => 'Marketing']);
        $em->persist($this->marketing);
        $em->flush();

        $em->persist(new AttributeGroupAttribute($this->marketing, $this->material, 1));
        $em->persist(new ObjectTypeAttribute($product, $this->material, false, 1));
        $em->persist(new ObjectTypeAttributeGroup($product, $this->marketing, position: 5));
        foreach (['s', 'm', 'l'] as $position => $code) {
            $em->persist(new AttributeOption($this->size, $code, ['en' => strtoupper($code)], $position));
        }

        foreach (['SKU-BATCH-1', 'SKU-BATCH-2'] as $code) {
            $object = new CatalogObject($product, $code);
            $em->persist($object);
            $em->flush();
            $em->persist(new ObjectValue($object, $this->material, ['value' => 'sample'], Provenance::Manual));
        }
        $em->flush();

        $this->seedLimitedUsers($tenant);
        $this->foreignAttributeId = $this->seedForeignTenantAttribute();

        $tenantContext->clear();
    }

    #[Test]
    public function attributesBatchMatchesPerItemPayload(): void
    {
        $ids = [$this->material->getId()->toRfc4122(), $this->size->getId()->toRfc4122()];

        $this->clearUsageCache();
        $client = $this->authenticatedClient();
        $expected = [];
        foreach ($ids as $id) {
            $expected[$id] = $client->request('GET', '/api/attributes/'.$id.'/usage')->toArray();
        }

        $this->clearUsageCache();
        $response = $client->request('GET', '/api/modeling/usage/attributes?ids='.implode(',', $ids));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($expected, $response->toArray());
    }

    #[Test]
    public function attributesBatchCarriesGroupsInstanceAndOptionCounts(): void
    {
        $client = $this->authenticatedClient();
        $materialId = $this->material->getId()->toRfc4122();
        $sizeId = $this->size->getId()->toRfc4122();

        $payload = $client->request(
            'GET',
            '/api/modeling/usage/attributes?ids='.$materialId.','.$sizeId,
        )->toArray();

        $material = $payload[$materialId];
        self::assertIsArray($material);
        self::assertIsArray($material['groups']);
        self::assertCount(1, $material['groups']);
        self::assertSame(2, $material['instanceCount']);
        // Not a select attribute — no options, but the key must still be there
        // so the list can render "0 wartości" without an undefined check.
        self::assertSame(0, $material['optionCount']);

        $size = $payload[$sizeId];
        self::assertIsArray($size);
        self::assertSame(3, $size['optionCount']);
    }

    #[Test]
    public function attributeGroupsBatchMatchesPerItemPayload(): void
    {
        $id = $this->marketing->getId()->toRfc4122();

        $this->clearUsageCache();
        $client = $this->authenticatedClient();
        $expected = $client->request('GET', '/api/attribute_groups/'.$id.'/usage')->toArray();

        $this->clearUsageCache();
        $response = $client->request('GET', '/api/modeling/usage/attribute-groups?ids='.$id);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([$id => $expected], $response->toArray());
        self::assertSame(2, $expected['affectedInstanceCount']);
    }

    #[Test]
    public function objectTypesBatchMatchesPerItemPayload(): void
    {
        $id = $this->product->getId()->toRfc4122();

        $this->clearUsageCache();
        $client = $this->authenticatedClient();
        $expected = $client->request('GET', '/api/object_types/'.$id.'/usage')->toArray();

        $this->clearUsageCache();
        $response = $client->request('GET', '/api/modeling/usage/object-types?ids='.$id);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([$id => $expected], $response->toArray());
    }

    /**
     * The batch has no per-row 404 to hide behind, and `pim.modeling_cache` is
     * not tenant-namespaced. Answering a foreign id with a zeroed payload would
     * write that zero under the owning tenant's cache key.
     */
    #[Test]
    public function batchOmitsIdsOwnedByAnotherTenant(): void
    {
        $mine = $this->material->getId()->toRfc4122();

        $response = $this->authenticatedClient()->request(
            'GET',
            '/api/modeling/usage/attributes?ids='.$mine.','.$this->foreignAttributeId,
        );

        self::assertSame(200, $response->getStatusCode());
        $payload = $response->toArray();
        self::assertArrayHasKey($mine, $payload);
        self::assertArrayNotHasKey($this->foreignAttributeId, $payload);
    }

    #[Test]
    public function unknownButWellFormedIdYieldsAnEmptyObject(): void
    {
        $response = $this->authenticatedClient()->request(
            'GET',
            '/api/modeling/usage/attributes?ids='.Uuid::v7()->toRfc4122(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $response->toArray());
        // `[]` in PHP encodes as a JSON array; the contract is a keyed object.
        self::assertStringContainsString('{}', $response->getContent());
    }

    #[Test]
    public function duplicateIdsAreCollapsed(): void
    {
        $id = $this->material->getId()->toRfc4122();

        $payload = $this->authenticatedClient()->request(
            'GET',
            '/api/modeling/usage/attributes?ids='.$id.','.$id.','.$id,
        )->toArray();

        self::assertCount(1, $payload);
    }

    #[Test]
    public function malformedIdIsRejected(): void
    {
        $response = $this->authenticatedClient()->request(
            'GET',
            '/api/modeling/usage/attributes?ids=not-a-uuid',
            ['headers' => ['accept' => 'application/json']],
        );

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function missingIdsParameterIsRejected(): void
    {
        $response = $this->authenticatedClient()->request(
            'GET',
            '/api/modeling/usage/attributes',
            ['headers' => ['accept' => 'application/json']],
        );

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function moreIdsThanTheCeilingAreRejected(): void
    {
        $ids = [];
        for ($i = 0; $i <= UsageQueryService::MAX_BATCH_IDS; ++$i) {
            $ids[] = Uuid::v7()->toRfc4122();
        }

        $response = $this->authenticatedClient()->request(
            'GET',
            '/api/modeling/usage/attributes?ids='.implode(',', $ids),
            ['headers' => ['accept' => 'application/json']],
        );

        self::assertSame(400, $response->getStatusCode());
    }

    /**
     * `catalog_manager` holds `products.view` but not `modeling.view`. The
     * per-item route lets it through via the `anyOf` grant set (#2881: schema
     * metadata reads follow the data they describe), so the batch must too —
     * otherwise the product form would lose its attribute metadata.
     */
    #[Test]
    public function roleWithoutModelingViewStillReachesTheBatch(): void
    {
        $response = $this->authenticatedClient(self::LIMITED_EMAIL)->request(
            'GET',
            '/api/modeling/usage/attributes?ids='.$this->material->getId()->toRfc4122(),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function roleWithoutAnyGrantIsForbidden(): void
    {
        $response = $this->authenticatedClient(self::UNGRANTED_EMAIL)->request(
            'GET',
            '/api/modeling/usage/attributes?ids='.$this->material->getId()->toRfc4122(),
            ['headers' => ['accept' => 'application/json']],
        );

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function unauthenticatedAccessReturns401(): void
    {
        $response = static::createClient()->request(
            'GET',
            '/api/modeling/usage/attributes?ids='.$this->material->getId()->toRfc4122(),
            ['headers' => ['accept' => 'application/json']],
        );

        self::assertSame(401, $response->getStatusCode());
    }

    private function clearUsageCache(): void
    {
        self::getContainer()->get('pim.modeling_cache')
            ->invalidateTags([UsageQueryService::CACHE_TAG]);
    }

    private function seedLimitedUsers(Tenant $tenant): void
    {
        $em = $this->em();
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $catalogManager = self::getContainer()->get(RoleRepositoryInterface::class)
            ->findByCode('catalog_manager', $tenant);
        \assert($catalogManager instanceof Role);

        $limitedStub = new User($tenant, self::LIMITED_EMAIL, '', ['ROLE_USER']);
        $limited = new User($tenant, self::LIMITED_EMAIL, $hasher->hashPassword($limitedStub, 'changeme'), ['ROLE_USER']);
        $limited->addRole($catalogManager);
        $em->persist($limited);

        // A role that grants nothing — the only way to reach 403, since every
        // seeded PRD role holds at least `products.view`.
        $empty = new Role(code: 'no_grants', name: 'No grants', tenant: $tenant);
        $em->persist($empty);

        $ungrantedStub = new User($tenant, self::UNGRANTED_EMAIL, '', ['ROLE_USER']);
        $ungranted = new User($tenant, self::UNGRANTED_EMAIL, $hasher->hashPassword($ungrantedStub, 'changeme'), ['ROLE_USER']);
        $ungranted->addRole($empty);
        $em->persist($ungranted);

        $em->flush();
    }

    private function seedForeignTenantAttribute(): string
    {
        $em = $this->em();
        $tenantContext = self::getContainer()->get(TenantContext::class);

        $other = new Tenant(self::OTHER_TENANT_CODE, 'Other Tenant');
        $em->persist($other);
        $em->flush();

        $tenantContext->set($other);
        $foreign = new Attribute('foreign_only', ['en' => 'Foreign only'], AttributeType::Text);
        $em->persist($foreign);
        $em->flush();

        $tenantContext->clear();

        return $foreign->getId()->toRfc4122();
    }
}
