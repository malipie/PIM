<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\AttributeGroupAttribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttributeGroup;
use App\Catalog\Domain\ObjectKind;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * VIEW-01b (#413) — direct attribute attach/detach to ObjectType endpoints
 * powering the modeling Detail view's "Custom attribute" card. Junction
 * lives in `object_type_attributes`; flow is independent from the
 * AttributeGroup pathway (an attribute can be visible via a group AND/OR
 * via direct attach simultaneously).
 */
final class ObjectTypeAttachedAttributesApiTest extends CatalogApiTestCase
{
    private string $customTypeId;
    private string $colorAttrId;
    private string $sizeAttrId;
    private string $weightAttrId;
    /** Attribute that already lives in an AttributeGroup attached to the OT. */
    private string $groupedAttrId;
    private string $groupId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $em = $this->em();

        $custom = new ObjectType('subscription', ObjectKind::Custom, ['en' => 'Subscription']);
        $em->persist($custom);

        $color = new Attribute('color', ['en' => 'Color'], AttributeType::Text);
        $size = new Attribute('size', ['en' => 'Size'], AttributeType::Text);
        $weight = new Attribute('weight', ['en' => 'Weight'], AttributeType::Number);
        $grouped = new Attribute('plan_tier', ['en' => 'Plan tier'], AttributeType::Text);
        $em->persist($color);
        $em->persist($size);
        $em->persist($weight);

        $group = new AttributeGroup('subscription_group', ['en' => 'Subscription Group']);
        $em->persist($group);
        // The legacy FK `attributes.group_id` is what the GET endpoint reads
        // for the `group` field — set it explicitly so the response carries
        // the membership when the operator direct-attaches an already-grouped
        // attribute. Junction `attribute_group_attributes` is the M:N path
        // for visible_when rules and per-group ordering.
        $grouped->assignToGroup($group);
        $em->persist($grouped);
        $em->flush();
        $em->persist(new AttributeGroupAttribute($group, $grouped, 1));
        $em->persist(new ObjectTypeAttributeGroup($custom, $group));
        $em->flush();

        $this->customTypeId = $custom->getId()->toRfc4122();
        $this->colorAttrId = $color->getId()->toRfc4122();
        $this->sizeAttrId = $size->getId()->toRfc4122();
        $this->weightAttrId = $weight->getId()->toRfc4122();
        $this->groupedAttrId = $grouped->getId()->toRfc4122();
        $this->groupId = $group->getId()->toRfc4122();

        self::getContainer()->get(TenantContext::class)->clear();
    }

    #[Test]
    public function getAttachedAttributesEmptyForFreshCustomType(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request('GET', '/api/object_types/'.$this->customTypeId.'/attached_attributes');

        self::assertResponseIsSuccessful();
        $payload = $response->toArray();
        self::assertSame([], $payload);
    }

    #[Test]
    public function attachAttributeIsIdempotent(): void
    {
        $client = $this->authenticatedClient();
        $uri = '/api/object_types/'.$this->customTypeId.'/attributes/'.$this->colorAttrId;

        $first = $client->request('POST', $uri);
        self::assertSame(Response::HTTP_NO_CONTENT, $first->getStatusCode());

        $second = $client->request('POST', $uri);
        self::assertSame(Response::HTTP_NO_CONTENT, $second->getStatusCode());

        self::assertSame(1, $this->countDirectAttachments($this->customTypeId, $this->colorAttrId));
    }

    #[Test]
    public function attachListPreservesSortOrder(): void
    {
        $client = $this->authenticatedClient();
        // Attach in deliberate order: weight → color → size.
        $client->request('POST', '/api/object_types/'.$this->customTypeId.'/attributes/'.$this->weightAttrId);
        $client->request('POST', '/api/object_types/'.$this->customTypeId.'/attributes/'.$this->colorAttrId);
        $client->request('POST', '/api/object_types/'.$this->customTypeId.'/attributes/'.$this->sizeAttrId);

        $response = $client->request('GET', '/api/object_types/'.$this->customTypeId.'/attached_attributes');
        self::assertResponseIsSuccessful();
        /** @var list<array{code: string, sortOrder: int}> $payload */
        $payload = $response->toArray();

        $codes = array_map(static fn (array $row): string => $row['code'], $payload);
        self::assertSame(['weight', 'color', 'size'], $codes);
        // sort_order ascends 0/1/2 — the controller appends in order.
        self::assertSame(0, $payload[0]['sortOrder']);
        self::assertSame(1, $payload[1]['sortOrder']);
        self::assertSame(2, $payload[2]['sortOrder']);
    }

    #[Test]
    public function detachExistingReturns204AndRemovesRow(): void
    {
        $client = $this->authenticatedClient();
        $uri = '/api/object_types/'.$this->customTypeId.'/attributes/'.$this->colorAttrId;
        $client->request('POST', $uri);

        $response = $client->request('DELETE', $uri);
        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());

        self::assertSame(0, $this->countDirectAttachments($this->customTypeId, $this->colorAttrId));
    }

    #[Test]
    public function detachMissingReturns204IdempotentSemantics(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request(
            'DELETE',
            '/api/object_types/'.$this->customTypeId.'/attributes/'.$this->colorAttrId,
        );
        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    }

    #[Test]
    public function bulkAttachCreatesAllRowsIdempotently(): void
    {
        $client = $this->authenticatedClient();
        $body = ['attributeIds' => [$this->colorAttrId, $this->sizeAttrId, $this->weightAttrId]];

        $first = $client->request('POST', '/api/object_types/'.$this->customTypeId.'/attributes/bulk-attach', [
            'json' => $body,
            'headers' => ['content-type' => 'application/json'],
        ]);
        self::assertSame(Response::HTTP_NO_CONTENT, $first->getStatusCode());

        $second = $client->request('POST', '/api/object_types/'.$this->customTypeId.'/attributes/bulk-attach', [
            'json' => $body,
            'headers' => ['content-type' => 'application/json'],
        ]);
        self::assertSame(Response::HTTP_NO_CONTENT, $second->getStatusCode());

        self::assertSame(3, $this->countDirectAttachments($this->customTypeId, null));
    }

    #[Test]
    public function attachUnknownAttributeReturns404(): void
    {
        $client = $this->authenticatedClient();
        $randomUuid = '00000000-0000-7000-8000-000000000000';
        $client->request('POST', '/api/object_types/'.$this->customTypeId.'/attributes/'.$randomUuid);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function attachUnknownObjectTypeReturns404(): void
    {
        $client = $this->authenticatedClient();
        $randomUuid = '00000000-0000-7000-8000-000000000000';
        $client->request('POST', '/api/object_types/'.$randomUuid.'/attributes/'.$this->colorAttrId);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function directAttachIndependentOfGroupAttach(): void
    {
        $client = $this->authenticatedClient();
        // The grouped attribute is already wired through `subscription_group`
        // attached to the custom OT. Direct-attach it on top — the response
        // should expose the row with `group: { id, code }` populated.
        $client->request('POST', '/api/object_types/'.$this->customTypeId.'/attributes/'.$this->groupedAttrId);

        $response = $client->request('GET', '/api/object_types/'.$this->customTypeId.'/attached_attributes');
        self::assertResponseIsSuccessful();
        /** @var list<array{code: string, group: array{id: string, code: string}|null}> $payload */
        $payload = $response->toArray();
        self::assertCount(1, $payload);
        self::assertSame('plan_tier', $payload[0]['code']);
        $group = $payload[0]['group'];
        self::assertNotNull($group);
        self::assertSame($this->groupId, $group['id']);
        self::assertSame('subscription_group', $group['code']);

        // Detaching the direct row leaves the group membership intact.
        $client->request('DELETE', '/api/object_types/'.$this->customTypeId.'/attributes/'.$this->groupedAttrId);

        $remaining = $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM attribute_group_attributes WHERE attribute_group_id = ? AND attribute_id = ?',
            [$this->groupId, $this->groupedAttrId],
        );
        self::assertIsScalar($remaining);
        self::assertSame(1, (int) $remaining);
    }

    #[Test]
    public function attachAttributeToClosedSystemKindReturns422(): void
    {
        // Amends ADR-009 — Asset / Category are closed system kinds: their
        // schema is platform-managed and rejects direct attribute attach.
        $client = $this->authenticatedClient();

        foreach ([ObjectKind::Asset, ObjectKind::Category] as $kind) {
            $typeId = $this->objectTypeIdFor($kind);
            $client->request('POST', '/api/object_types/'.$typeId.'/attributes/'.$this->colorAttrId);
            self::assertResponseStatusCodeSame(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                \sprintf('Attaching to kind=%s must be rejected.', $kind->value),
            );
            self::assertSame(0, $this->countDirectAttachments($typeId, $this->colorAttrId));
        }
    }

    #[Test]
    public function bulkAttachToClosedSystemKindReturns422(): void
    {
        $client = $this->authenticatedClient();
        $typeId = $this->objectTypeIdFor(ObjectKind::Asset);

        $client->request('POST', '/api/object_types/'.$typeId.'/attributes/bulk-attach', [
            'json' => ['attributeIds' => [$this->colorAttrId, $this->sizeAttrId]],
            'headers' => ['content-type' => 'application/json'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(0, $this->countDirectAttachments($typeId, null));
    }

    private function countDirectAttachments(string $objectTypeId, ?string $attributeId): int
    {
        $sql = 'SELECT COUNT(*) FROM object_type_attributes WHERE object_type_id = ?';
        $params = [$objectTypeId];
        if (null !== $attributeId) {
            $sql .= ' AND attribute_id = ?';
            $params[] = $attributeId;
        }
        $raw = $this->em()->getConnection()->fetchOne($sql, $params);
        \assert(\is_scalar($raw));

        return (int) $raw;
    }
}
