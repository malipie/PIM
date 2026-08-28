<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Application\BuiltInSystemAttributesSeeder;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\AttributeGroupAttribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectTypeAttributeGroup;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * MODR-01 (#923) — `PATCH /api/object_types/{id}/groups/{groupId}` updates
 * the per-assignment `display_mode` on the junction.
 */
final class ObjectTypeAttributeGroupDisplayModePatchApiTest extends CatalogApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert(null !== $tenant);
        self::getContainer()->get(BuiltInSystemAttributesSeeder::class)->seed($tenant);
    }

    #[Test]
    public function patchChangesDisplayModeToStacked(): void
    {
        $productId = $this->objectTypeIdFor(ObjectKind::Product);
        $groupCode = 'display_mode_specs';
        $groupId = $this->attachGroupToProductType($groupCode);

        $client = $this->authenticatedClient();

        $client->request('PATCH', '/api/object_types/'.$productId.'/groups/'.$groupId, [
            'json' => ['display_mode' => 'stacked'],
            'headers' => ['content-type' => 'application/json'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        // Form-schema for a product of this type must reflect the new mode.
        $product = $this->seedProduct('SKU-MODR01-001');
        $response = $client->request(
            'GET',
            '/api/objects/'.$product->getId()->toRfc4122().'/form-schema'
        );
        self::assertResponseIsSuccessful();

        $payload = $response->toArray();
        $groups = $payload['effectiveGroups'];
        self::assertIsArray($groups);
        $patchedGroup = null;
        foreach ($groups as $group) {
            \assert(\is_array($group));
            if (($group['code'] ?? null) === $groupCode) {
                $patchedGroup = $group;
                break;
            }
        }
        self::assertIsArray($patchedGroup);
        self::assertSame('stacked', $patchedGroup['display_mode']);
    }

    #[Test]
    public function patchRejectsInvalidDisplayMode(): void
    {
        $productId = $this->objectTypeIdFor(ObjectKind::Product);
        $groupId = $this->attachGroupToProductType('display_mode_invalid');

        $client = $this->authenticatedClient();
        $client->request('PATCH', '/api/object_types/'.$productId.'/groups/'.$groupId, [
            'json' => ['display_mode' => 'accordion'],
            'headers' => ['content-type' => 'application/json'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    #[Test]
    public function patchReturnsNotFoundForMissingAssignment(): void
    {
        $productId = $this->objectTypeIdFor(ObjectKind::Product);
        $unknownGroupId = Uuid::v7()->toRfc4122();

        $client = $this->authenticatedClient();
        $client->request('PATCH', '/api/object_types/'.$productId.'/groups/'.$unknownGroupId, [
            'json' => ['display_mode' => 'stacked'],
            'headers' => ['content-type' => 'application/json'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function patchReturnsBadRequestWhenBodyMissingField(): void
    {
        $productId = $this->objectTypeIdFor(ObjectKind::Product);
        $groupId = $this->attachGroupToProductType('display_mode_missing');

        $client = $this->authenticatedClient();
        $client->request('PATCH', '/api/object_types/'.$productId.'/groups/'.$groupId, [
            'json' => [],
            'headers' => ['content-type' => 'application/json'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    #[Test]
    public function entityRejectsInvalidDisplayMode(): void
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $type);
        $group = $this->seedGroup('display_mode_entity');

        $this->expectException(InvalidArgumentException::class);

        new ObjectTypeAttributeGroup($type, $group, 0, 'invalid_mode');
    }

    #[Test]
    public function patchUpdatesPosition(): void
    {
        $productId = $this->objectTypeIdFor(ObjectKind::Product);
        $groupId = $this->attachGroupToProductType('position_patch');

        $client = $this->authenticatedClient();
        $client->request('PATCH', '/api/object_types/'.$productId.'/groups/'.$groupId, [
            'json' => ['position' => 7],
            'headers' => ['content-type' => 'application/json'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $response = $client->request('GET', '/api/object_types/'.$productId.'/attached_groups');
        self::assertResponseIsSuccessful();
        $patched = $this->findGroup($response->toArray(), $groupId);
        self::assertSame(7, $patched['position']);
    }

    #[Test]
    public function patchAcceptsPositionAndDisplayModeTogether(): void
    {
        $productId = $this->objectTypeIdFor(ObjectKind::Product);
        $groupId = $this->attachGroupToProductType('position_and_mode');

        $client = $this->authenticatedClient();
        $client->request('PATCH', '/api/object_types/'.$productId.'/groups/'.$groupId, [
            'json' => ['position' => 3, 'display_mode' => 'stacked'],
            'headers' => ['content-type' => 'application/json'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $patched = $this->findGroup(
            $client->request('GET', '/api/object_types/'.$productId.'/attached_groups')->toArray(),
            $groupId,
        );
        self::assertSame(3, $patched['position']);
        self::assertSame('stacked', $patched['displayMode']);
    }

    #[Test]
    public function patchRejectsNegativePosition(): void
    {
        $productId = $this->objectTypeIdFor(ObjectKind::Product);
        $groupId = $this->attachGroupToProductType('position_negative');

        $client = $this->authenticatedClient();
        $client->request('PATCH', '/api/object_types/'.$productId.'/groups/'.$groupId, [
            'json' => ['position' => -1],
            'headers' => ['content-type' => 'application/json'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    /**
     * @param array<array-key, mixed> $entries
     *
     * @return array<array-key, mixed>
     */
    private function findGroup(array $entries, string $groupId): array
    {
        foreach ($entries as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            if (($entry['id'] ?? null) === $groupId) {
                return $entry;
            }
        }
        self::fail(\sprintf('Group "%s" not found in attached_groups response.', $groupId));
    }

    private function attachGroupToProductType(string $code): string
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($tenant);

        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $type);

        $group = new AttributeGroup($code, ['en' => 'Display mode test']);
        $attribute = new Attribute($code.'_field', ['en' => 'Display mode field'], AttributeType::Text);

        $em = $this->em();
        $em->persist($group);
        $em->persist($attribute);
        $em->flush();
        $em->persist(new AttributeGroupAttribute($group, $attribute, 1));
        $em->persist(new ObjectTypeAttributeGroup($type, $group, 1));
        $em->flush();

        $tenantContext->clear();

        return $group->getId()->toRfc4122();
    }

    private function seedGroup(string $code): AttributeGroup
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($tenant);

        $group = new AttributeGroup($code, ['en' => 'Display mode entity']);
        $em = $this->em();
        $em->persist($group);
        $em->flush();

        $tenantContext->clear();

        return $group;
    }

    private function seedProduct(string $code): CatalogObject
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert(null !== $tenant);

        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($tenant);

        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $type);

        $product = new CatalogObject($type, $code);
        $em = $this->em();
        $em->persist($product);
        $em->flush();

        $tenantContext->clear();

        return $product;
    }
}
