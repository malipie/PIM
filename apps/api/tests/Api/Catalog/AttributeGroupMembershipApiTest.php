<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Application\BuiltInSystemAttributesSeeder;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\AttributeGroupAttribute;
use App\Catalog\Domain\Repository\AttributeGroupRepositoryInterface;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;

/**
 * VIEW-03 (#375) — covers the 3 membership endpoints feeding the
 * Attributes-in-this-group card on the AttributeGroupDetail mockup:
 *  - POST /api/attribute_groups/{id}/attributes/bulk-attach (popup "Z biblioteki")
 *  - DELETE /api/attribute_groups/{id}/attributes/{attributeId} (per-row trash)
 *  - POST /api/attribute_groups/{id}/attributes/reorder (drag-reorder)
 */
final class AttributeGroupMembershipApiTest extends CatalogApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(BuiltInSystemAttributesSeeder::class)->seed($tenant);
    }

    #[Test]
    public function bulkAttachAddsNewMembersAndSkipsDuplicates(): void
    {
        $client = $this->authenticatedClient();
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        // Seed 2 attributes the test will attach.
        $this->seedAttribute('brand', AttributeType::Text);
        $this->seedAttribute('warranty_months', AttributeType::Number);

        // Create a fresh business group via API so we exercise the full path.
        $created = $client->request('POST', '/api/attribute_groups', [
            'json' => ['code' => 'marketing', 'label' => ['pl' => 'Marketing']],
        ]);
        $groupId = $created->toArray()['id'] ?? null;
        \assert(\is_string($groupId));

        // First call: both codes attached.
        $resp1 = $client->request('POST', '/api/attribute_groups/'.$groupId.'/attributes/bulk-attach', [
            'json' => ['attributeCodes' => ['brand', 'warranty_months']],
        ]);
        self::assertSame(200, $resp1->getStatusCode());
        $payload1 = $resp1->toArray();
        self::assertSame(['brand', 'warranty_months'], $payload1['attached']);

        // Second call: no-op idempotent (both codes already in the group).
        $resp2 = $client->request('POST', '/api/attribute_groups/'.$groupId.'/attributes/bulk-attach', [
            'json' => ['attributeCodes' => ['brand']],
        ]);
        self::assertSame(200, $resp2->getStatusCode());
        self::assertSame([], $resp2->toArray()['attached']);
    }

    #[Test]
    public function bulkAttachRejectsUnknownCode(): void
    {
        $client = $this->authenticatedClient();
        $created = $client->request('POST', '/api/attribute_groups', [
            'json' => ['code' => 'logistics', 'label' => ['pl' => 'Logistyka']],
        ]);
        $groupId = $created->toArray()['id'] ?? null;
        \assert(\is_string($groupId));

        $resp = $client->request('POST', '/api/attribute_groups/'.$groupId.'/attributes/bulk-attach', [
            'json' => ['attributeCodes' => ['nonexistent_attr_code']],
        ]);

        self::assertSame(422, $resp->getStatusCode());
    }

    #[Test]
    public function detachRemovesJunctionWithoutDeletingAttribute(): void
    {
        $client = $this->authenticatedClient();
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $attrId = $this->seedAttribute('brand', AttributeType::Text);
        $created = $client->request('POST', '/api/attribute_groups', [
            'json' => ['code' => 'marketing', 'label' => ['pl' => 'Marketing']],
        ]);
        $groupId = $created->toArray()['id'] ?? null;
        \assert(\is_string($groupId));

        $client->request('POST', '/api/attribute_groups/'.$groupId.'/attributes/bulk-attach', [
            'json' => ['attributeCodes' => ['brand']],
        ]);

        $resp = $client->request('DELETE', '/api/attribute_groups/'.$groupId.'/attributes/'.$attrId->toRfc4122());
        self::assertSame(204, $resp->getStatusCode());

        // Attribute itself stays in the global library.
        $repo = self::getContainer()->get(AttributeRepositoryInterface::class);
        self::assertNotNull($repo->findById($attrId));
    }

    #[Test]
    public function reorderAppliesNewPositionsInPayloadOrder(): void
    {
        $client = $this->authenticatedClient();
        $aId = $this->seedAttribute('color', AttributeType::Text);
        $bId = $this->seedAttribute('brand', AttributeType::Text);
        $cId = $this->seedAttribute('size', AttributeType::Text);
        unset($aId, $bId, $cId);

        $created = $client->request('POST', '/api/attribute_groups', [
            'json' => ['code' => 'marketing', 'label' => ['pl' => 'Marketing']],
        ]);
        $groupId = $created->toArray()['id'] ?? null;
        \assert(\is_string($groupId));

        $client->request('POST', '/api/attribute_groups/'.$groupId.'/attributes/bulk-attach', [
            'json' => ['attributeCodes' => ['color', 'brand', 'size']],
        ]);

        $resp = $client->request('POST', '/api/attribute_groups/'.$groupId.'/attributes/reorder', [
            'json' => ['order' => ['size', 'color', 'brand']],
        ]);
        self::assertSame(204, $resp->getStatusCode());

        // Verify positions: size=0, color=1, brand=2.
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        $group = self::getContainer()->get(AttributeGroupRepositoryInterface::class)->findByCode('marketing', $tenant);
        \assert($group instanceof AttributeGroup);

        $junctions = $em->getRepository(AttributeGroupAttribute::class)->findBy(['attributeGroup' => $group]);
        $byCode = [];
        foreach ($junctions as $j) {
            $byCode[$j->getAttribute()->getCode()] = $j->getPosition();
        }
        self::assertSame(0, $byCode['size']);
        self::assertSame(1, $byCode['color']);
        self::assertSame(2, $byCode['brand']);
    }

    #[Test]
    public function reorderRejectsSizeMismatch(): void
    {
        $client = $this->authenticatedClient();
        $this->seedAttribute('color', AttributeType::Text);
        $this->seedAttribute('brand', AttributeType::Text);

        $created = $client->request('POST', '/api/attribute_groups', [
            'json' => ['code' => 'marketing', 'label' => ['pl' => 'Marketing']],
        ]);
        $groupId = $created->toArray()['id'] ?? null;
        \assert(\is_string($groupId));

        $client->request('POST', '/api/attribute_groups/'.$groupId.'/attributes/bulk-attach', [
            'json' => ['attributeCodes' => ['color', 'brand']],
        ]);

        $resp = $client->request('POST', '/api/attribute_groups/'.$groupId.'/attributes/reorder', [
            'json' => ['order' => ['color']],
        ]);
        self::assertSame(422, $resp->getStatusCode());
    }

    private function seedAttribute(string $code, AttributeType $type): \Symfony\Component\Uid\Uuid
    {
        $ctx = self::getContainer()->get(TenantContext::class);
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        $ctx->set($tenant);

        $attribute = new Attribute($code, ['en' => $code], $type);
        $repo = self::getContainer()->get(AttributeRepositoryInterface::class);
        $repo->save($attribute);

        return $attribute->getId();
    }
}
