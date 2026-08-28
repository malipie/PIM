<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\ObjectKind;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

use const JSON_THROW_ON_ERROR;

/**
 * #979 — `GET /api/attributes/{id}/owner_object_types` exposes the list of
 * ObjectType UUIDs that own a given attribute through the
 * `object_type_attributes` junction.
 *
 * Mutations stay on the existing
 * `/api/object_types/{id}/attributes/{attributeId}` POST/DELETE endpoints
 * (covered separately); this suite asserts the read-side wiring and the
 * tenant isolation guard, plus the happy round-trip through attach/detach.
 */
final class AttributeOwnerObjectTypesApiTest extends CatalogApiTestCase
{
    #[Test]
    public function unauthenticatedRequestReturns401(): void
    {
        static::createClient()->request(
            'GET',
            '/api/attributes/01234567-1234-7000-8000-000000000000/owner_object_types',
        );
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function getReturnsEmptyListWhenAttributeHasNoOwners(): void
    {
        $client = $this->authenticatedClient();
        $attr = $this->seedAttribute('mod_attr_no_owners');

        $body = $client->request(
            'GET',
            '/api/attributes/'.$attr->getId()->toRfc4122().'/owner_object_types',
        )->toArray();

        self::assertSame($attr->getId()->toRfc4122(), $body['attributeId']);
        self::assertSame([], $body['objectTypeIds']);
    }

    #[Test]
    public function getReflectsAttachAndDetachRoundTrip(): void
    {
        $client = $this->authenticatedClient();
        $attr = $this->seedAttribute('mod_attr_round_trip');
        $productOtId = $this->objectTypeIdFor(ObjectKind::Product);

        // Initially empty.
        $body = $client->request(
            'GET',
            '/api/attributes/'.$attr->getId()->toRfc4122().'/owner_object_types',
        )->toArray();
        self::assertSame([], $body['objectTypeIds']);

        // Attach via existing endpoint.
        $client->request(
            'POST',
            '/api/object_types/'.$productOtId.'/attributes/'.$attr->getId()->toRfc4122(),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        // Now the GET should list the Product ObjectType.
        $body = $client->request(
            'GET',
            '/api/attributes/'.$attr->getId()->toRfc4122().'/owner_object_types',
        )->toArray();
        self::assertSame([$productOtId], $body['objectTypeIds']);

        // Detach.
        $client->request(
            'DELETE',
            '/api/object_types/'.$productOtId.'/attributes/'.$attr->getId()->toRfc4122(),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        // Back to empty.
        $body = $client->request(
            'GET',
            '/api/attributes/'.$attr->getId()->toRfc4122().'/owner_object_types',
        )->toArray();
        self::assertSame([], $body['objectTypeIds']);
    }

    #[Test]
    public function ownerListReflectsModelableAttachesAndExcludesClosedSystemKinds(): void
    {
        $client = $this->authenticatedClient();
        // `code` is unique per tenant. Product is attribute-modelable; Category
        // is a closed system kind (amends ADR-009) whose schema is platform-
        // managed and rejects attribute attach — so it must never surface among
        // an attribute's owner ObjectTypes.
        $attr = $this->seedAttribute('mod_attr_multi_owner');

        $productOtId = $this->objectTypeIdFor(ObjectKind::Product);
        $categoryOtId = $this->objectTypeIdFor(ObjectKind::Category);

        // Product attach succeeds (attribute-modelable kind).
        $client->request('POST', '/api/object_types/'.$productOtId.'/attributes/bulk-attach', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['attributeIds' => [$attr->getId()->toRfc4122()]], JSON_THROW_ON_ERROR),
        ]);

        // Category attach is rejected — closed system kind.
        $client->request('POST', '/api/object_types/'.$categoryOtId.'/attributes/bulk-attach', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['attributeIds' => [$attr->getId()->toRfc4122()]], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        // The owner endpoint lists only the modelable (Product) owner.
        $body = $client->request(
            'GET',
            '/api/attributes/'.$attr->getId()->toRfc4122().'/owner_object_types',
        )->toArray();

        self::assertSame([$productOtId], $body['objectTypeIds']);
    }

    #[Test]
    public function getReturns404ForUnknownAttribute(): void
    {
        $client = $this->authenticatedClient();
        $client->request(
            'GET',
            '/api/attributes/01234567-1234-7000-8000-000000000000/owner_object_types',
        );
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function seedAttribute(string $code): Attribute
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($tenant);

        $attr = new Attribute($code, ['pl' => 'Test', 'en' => 'Test'], AttributeType::Text);
        $em = $this->em();
        $em->persist($attr);
        $em->flush();

        $tenantContext->clear();

        return $attr;
    }
}
