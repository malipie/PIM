<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Application\BuiltInSystemAttributesSeeder;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\AttributeGroupAttribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\Entity\ObjectTypeAttributeGroup;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * UI-08.7 (#262) — `where-used` endpoint smoke + count contracts.
 */
final class UsageApiTest extends CatalogApiTestCase
{
    private Attribute $material;
    private AttributeGroup $marketing;
    private ObjectType $product;

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
        \assert(null !== $product);
        $this->product = $product;

        // Attribute "material" + Group "marketing" + junction.
        $this->material = new Attribute('material', ['en' => 'Material'], AttributeType::Text);
        $em->persist($this->material);
        $this->marketing = new AttributeGroup('marketing', ['en' => 'Marketing']);
        $em->persist($this->marketing);
        $em->flush();
        $em->persist(new AttributeGroupAttribute($this->marketing, $this->material, 1));
        $em->persist(new ObjectTypeAttribute($product, $this->material, false, 1));
        $em->persist(new ObjectTypeAttributeGroup($product, $this->marketing, position: 5));

        // Two CatalogObject rows with values for the attribute.
        foreach (['SKU-USAGE-1', 'SKU-USAGE-2'] as $code) {
            $obj = new CatalogObject($product, $code);
            $em->persist($obj);
            $em->flush();
            $em->persist(new \App\Catalog\Domain\Entity\ObjectValue(
                $obj,
                $this->material,
                ['value' => 'sample'],
                \App\Catalog\Domain\Provenance::Manual,
            ));
        }
        $em->flush();

        $tenantContext->clear();
    }

    #[Test]
    public function attributeUsageReturnsGroupsAndInstanceCount(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request('GET', '/api/attributes/'.$this->material->getId()->toRfc4122().'/usage');

        self::assertSame(200, $response->getStatusCode());
        $payload = $response->toArray();
        $groups = $payload['groups'];
        self::assertIsArray($groups);
        self::assertCount(1, $groups);
        self::assertIsArray($groups[0]);
        self::assertSame('marketing', $groups[0]['code']);
        self::assertIsArray($payload['objectTypes']);
        self::assertCount(1, $payload['objectTypes']);
        self::assertSame(2, $payload['instanceCount']);
    }

    #[Test]
    public function attributeUsageReturns404ForUnknownId(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request('GET', '/api/attributes/'.Uuid::v7()->toRfc4122().'/usage');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function attributeGroupUsageReportsAttachmentsAndAffectedInstances(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request('GET', '/api/attribute_groups/'.$this->marketing->getId()->toRfc4122().'/usage');

        self::assertSame(200, $response->getStatusCode());
        $payload = $response->toArray();
        self::assertIsArray($payload['directlyAttachedTo']);
        self::assertIsArray($payload['directlyAttachedTo']['objectTypes']);
        self::assertCount(1, $payload['directlyAttachedTo']['objectTypes']);
        self::assertSame(1, $payload['attributeCount']);
        self::assertSame(2, $payload['affectedInstanceCount']);
    }

    #[Test]
    public function attributeGroupUsageReturns404ForUnknownId(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request('GET', '/api/attribute_groups/'.Uuid::v7()->toRfc4122().'/usage');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function objectTypeUsageReportsInstanceCountAndAttachments(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request('GET', '/api/object_types/'.$this->product->getId()->toRfc4122().'/usage');

        self::assertSame(200, $response->getStatusCode());
        $payload = $response->toArray();
        self::assertSame(2, $payload['instanceCount']);
        self::assertGreaterThanOrEqual(1, $payload['attributesAttachedCount']);
        self::assertGreaterThanOrEqual(1, $payload['attributeGroupsAttachedCount']);
        self::assertSame(0, $payload['referencedByApiProfileCount']);
    }

    #[Test]
    public function objectTypeUsageIsInvalidatedImmediatelyAfterInstanceRemoval(): void
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $object = new CatalogObject($this->product, 'SKU-USAGE-CACHE');
        $this->em()->persist($object);
        $this->em()->flush();

        $client = $this->authenticatedClient();
        $primed = $client->request('GET', '/api/object_types/'.$this->product->getId()->toRfc4122().'/usage');
        self::assertSame(3, $primed->toArray()['instanceCount'] ?? null);

        self::getContainer()->get(TenantContext::class)->set($tenant);
        $managedObject = $this->em()->find(CatalogObject::class, $object->getId());
        \assert($managedObject instanceof CatalogObject);
        $this->em()->remove($managedObject);
        $this->em()->flush();

        $fresh = $client->request('GET', '/api/object_types/'.$this->product->getId()->toRfc4122().'/usage');
        self::assertSame(2, $fresh->toArray()['instanceCount'] ?? null);
    }

    #[Test]
    public function unauthenticatedAccessReturns401(): void
    {
        $client = static::createClient();
        $response = $client->request('GET', '/api/attributes/'.$this->material->getId()->toRfc4122().'/usage');

        self::assertSame(401, $response->getStatusCode());
    }
}
