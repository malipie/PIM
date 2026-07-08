<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Application\BuiltInSystemAttributesSeeder;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;

/**
 * VIEW-02 (#374) — Attribute CRUD ApiResource smoke + invariants.
 *
 * Mirrors AttributeGroupsApiTest (#260) for the new POST/PATCH/DELETE
 * operations. Asserts:
 *   - POST creates a tenant-scoped attribute with proper validation.
 *   - PATCH partial update touches only provided fields.
 *   - DELETE removes a custom attribute; system attributes (`is_system=true`)
 *     are 422 (UI-08.3 immutability).
 *   - Code uniqueness within tenant is enforced (409).
 *   - Code regex (snake_case) is validated (422).
 */
final class AttributesCrudApiTest extends CatalogApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        // Seeder creates `created_at`/`updated_at`/etc. system attrs we
        // exercise the delete-protection guard against.
        self::getContainer()->get(BuiltInSystemAttributesSeeder::class)->seed($tenant);
    }

    #[Test]
    public function postCreatesAttribute(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request('POST', '/api/attributes', [
            'json' => [
                'code' => 'warranty_months',
                'label' => ['en' => 'Warranty (months)', 'pl' => 'Gwarancja (msc)'],
                'type' => 'number',
                'localizable' => false,
                'required' => true,
                'validationRules' => ['min' => 0, 'max' => 120],
                'position' => 10,
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $payload = $response->toArray();
        self::assertSame('warranty_months', $payload['code']);
        self::assertSame('number', $payload['type']);
        self::assertTrue($payload['required']);
        self::assertSame(10, $payload['position']);
    }

    #[Test]
    public function collectionReturnsEveryAttributeBeyondTheLegacy200Cap(): void
    {
        // #2277 — the admin list fetches with pagination off; a 200 page
        // cap silently hid attributes past the 200th (CLAUDE.md target is
        // "200+ atrybutów"). Seed past the cap and assert the collection
        // returns them all, not a truncated 200.
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        for ($i = 0; $i < 205; ++$i) {
            $em->persist(new Attribute(
                \sprintf('cap_attr_%04d', $i),
                ['en' => 'Cap '.$i],
                AttributeType::Text,
            ));
        }
        $em->flush();

        $response = $this->authenticatedClient()->request('GET', '/api/attributes');
        self::assertSame(200, $response->getStatusCode());
        $payload = $response->toArray();
        $total = $payload['totalItems'] ?? $payload['hydra:totalItems'] ?? 0;
        $members = $payload['member'] ?? $payload['hydra:member'] ?? [];
        self::assertIsInt($total);
        self::assertIsArray($members);
        self::assertGreaterThan(200, $total, 'fixture must exceed the old cap');
        self::assertCount(
            $total,
            $members,
            'the collection must return every attribute, not truncate at 200',
        );
    }

    #[Test]
    public function postRejectsNonSnakeCaseCode(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request('POST', '/api/attributes', [
            'json' => [
                'code' => 'WarrantyMonths',
                'label' => ['en' => 'Warranty'],
                'type' => 'number',
            ],
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function postRejectsDuplicateCodeForSameTenant(): void
    {
        $this->seedAttribute('brand', AttributeType::Text);

        $client = $this->authenticatedClient();
        $response = $client->request('POST', '/api/attributes', [
            'json' => [
                'code' => 'brand',
                'label' => ['en' => 'Brand again'],
                'type' => 'text',
            ],
        ]);

        self::assertSame(409, $response->getStatusCode());
    }

    #[Test]
    public function patchUpdatesProvidedFieldsOnly(): void
    {
        $id = $this->seedAttribute('brand', AttributeType::Text);

        $client = $this->authenticatedClient();
        $response = $client->request('PATCH', '/api/attributes/'.$id->toRfc4122(), [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => [
                'label' => ['en' => 'Brand renamed', 'pl' => 'Marka'],
                'localizable' => true,
            ],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $payload = $response->toArray();
        self::assertSame(['en' => 'Brand renamed', 'pl' => 'Marka'], $payload['label']);
        self::assertTrue($payload['localizable']);
    }

    /**
     * #1350 — the edit form gained a "Wymagany" toggle; assert PATCH
     * actually flips `required` (and back), since the FE form was the
     * only thing missing — the endpoint already accepted the field.
     */
    #[Test]
    public function patchTogglesRequired(): void
    {
        $id = $this->seedAttribute('warranty_months', AttributeType::Number);
        $client = $this->authenticatedClient();

        $on = $client->request('PATCH', '/api/attributes/'.$id->toRfc4122(), [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['required' => true],
        ]);
        self::assertSame(200, $on->getStatusCode());
        self::assertTrue($on->toArray()['required']);

        $off = $client->request('PATCH', '/api/attributes/'.$id->toRfc4122(), [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['required' => false],
        ]);
        self::assertSame(200, $off->getStatusCode());
        self::assertFalse($off->toArray()['required']);
    }

    #[Test]
    public function deleteRemovesCustomAttribute(): void
    {
        $id = $this->seedAttribute('warranty_months', AttributeType::Number);

        $client = $this->authenticatedClient();
        $response = $client->request('DELETE', '/api/attributes/'.$id->toRfc4122());

        self::assertSame(204, $response->getStatusCode());

        $repo = self::getContainer()->get(AttributeRepositoryInterface::class);
        self::assertNull($repo->findById($id));
    }

    #[Test]
    public function deleteRejectsSystemAttributeWith422(): void
    {
        // BuiltInSystemAttributesSeeder seeds `created_at` as a system
        // attribute — picking it up by code ensures we hit the guard.
        $repo = self::getContainer()->get(AttributeRepositoryInterface::class);
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        $system = $repo->findByCode('created_at', $tenant);
        \assert($system instanceof Attribute);

        $client = $this->authenticatedClient();
        $response = $client->request('DELETE', '/api/attributes/'.$system->getId()->toRfc4122());

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function deleteRejectsAttributeInUseWith409(): void
    {
        // Attribute attached to an ObjectType → object_type_attributes FK is
        // ON DELETE RESTRICT, so the delete must be blocked with a clean 409.
        $id = $this->seedAttribute('warranty_months', AttributeType::Number);
        $this->attachAttributeToProductType($id);

        $client = $this->authenticatedClient();
        $response = $client->request('DELETE', '/api/attributes/'.$id->toRfc4122());

        self::assertSame(409, $response->getStatusCode());

        // Attribute must still exist after a rejected delete.
        $repo = self::getContainer()->get(AttributeRepositoryInterface::class);
        self::assertNotNull($repo->findById($id));
    }

    #[Test]
    public function postWithAttachToGroupsCreatesJunctionRows(): void
    {
        $client = $this->authenticatedClient();
        $client->request('POST', '/api/attribute_groups', [
            'json' => ['code' => 'marketing', 'label' => ['pl' => 'Marketing']],
        ]);

        $response = $client->request('POST', '/api/attributes', [
            'json' => [
                'code' => 'campaign_tag',
                'label' => ['en' => 'Campaign tag'],
                'type' => 'text',
                'attachToGroups' => ['marketing'],
            ],
        ]);
        self::assertSame(201, $response->getStatusCode());

        $list = $client->request('GET', '/api/attribute_groups/'.$this->resolveGroupId('marketing').'/attributes');
        $payload = $list->toArray();
        \assert(isset($payload['members']) && \is_array($payload['members']));
        $codes = [];
        foreach ($payload['members'] as $row) {
            \assert(\is_array($row));
            $attribute = $row['attribute'] ?? null;
            \assert(\is_array($attribute));
            $code = $attribute['code'] ?? '';
            \assert(\is_string($code));
            $codes[] = $code;
        }
        self::assertContains('campaign_tag', $codes);
    }

    #[Test]
    public function postWithUnknownGroupCodeReturns422(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request('POST', '/api/attributes', [
            'json' => [
                'code' => 'campaign_tag',
                'label' => ['en' => 'Campaign tag'],
                'type' => 'text',
                'attachToGroups' => ['nonexistent_group_code'],
            ],
        ]);
        self::assertSame(422, $response->getStatusCode());

        $repo = self::getContainer()->get(AttributeRepositoryInterface::class);
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::assertNull($repo->findByCode('campaign_tag', $tenant));
    }

    private function resolveGroupId(string $code): string
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        $repo = self::getContainer()->get(\App\Catalog\Domain\Repository\AttributeGroupRepositoryInterface::class);
        $group = $repo->findByCode($code, $tenant);
        \assert(null !== $group);

        return $group->getId()->toRfc4122();
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

    private function attachAttributeToProductType(\Symfony\Component\Uid\Uuid $attributeId): void
    {
        $ctx = self::getContainer()->get(TenantContext::class);
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        $ctx->set($tenant);

        $attribute = self::getContainer()->get(AttributeRepositoryInterface::class)->findById($attributeId);
        \assert($attribute instanceof Attribute);

        $objectType = self::getContainer()
            ->get(\App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(\App\Catalog\Domain\ObjectKind::Product, $tenant);
        \assert(null !== $objectType);

        $junction = new \App\Catalog\Domain\Entity\ObjectTypeAttribute($objectType, $attribute);
        self::getContainer()
            ->get(\App\Catalog\Domain\Repository\ObjectTypeAttributeRepositoryInterface::class)
            ->save($junction);
    }
}
