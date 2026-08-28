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
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * UI-08.4 (#259) — `GET /api/objects/{id}/form-schema` smoke.
 */
final class ObjectFormSchemaApiTest extends CatalogApiTestCase
{
    #[Test]
    public function getFormSchemaForBuiltInProductReturnsNoGroupsByDefault(): void
    {
        $product = $this->seedProduct('SKU-FS-001');
        $client = $this->authenticatedClient();

        $response = $client->request('GET', '/api/objects/'.$product->getId()->toRfc4122().'/form-schema');

        self::assertSame(200, $response->getStatusCode());
        $payload = $response->toArray();
        self::assertSame($product->getId()->toRfc4122(), $payload['objectId']);
        $type = $payload['objectType'];
        self::assertIsArray($type);
        self::assertSame('product', $type['kind']);
        $groups = $payload['effectiveGroups'];
        self::assertIsArray($groups);
        self::assertSame([], $groups);
    }

    #[Test]
    public function getFormSchemaExposesDisplayModePerGroup(): void
    {
        $product = $this->seedProduct('SKU-FS-DM-001');
        $this->attachGroupToProductType('display-mode', 'Display Mode');
        $client = $this->authenticatedClient();

        $response = $client->request('GET', '/api/objects/'.$product->getId()->toRfc4122().'/form-schema');

        self::assertSame(200, $response->getStatusCode());
        $payload = $response->toArray();
        $groups = $payload['effectiveGroups'];
        self::assertIsArray($groups);
        self::assertNotEmpty($groups);
        foreach ($groups as $group) {
            self::assertIsArray($group);
            self::assertArrayHasKey('display_mode', $group);
            self::assertContains($group['display_mode'], ['tab', 'stacked']);
        }
    }

    #[Test]
    public function getFormSchemaReturnsNotFoundForUnknownId(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request('GET', '/api/objects/'.Uuid::v7()->toRfc4122().'/form-schema');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function systemAttributesAreNotAutoRenderedWhenAuditGroupMissing(): void
    {
        // #1077 AC2: with the legacy audit group un-seeded, the form-schema
        // surface must NOT auto-expose the system attributes — visibility is
        // explicit modeling configuration after #1074. We seed the platform
        // attribute rows first to assert the BE doesn't "guess" them in.
        $this->seedSystemAttributes();

        $product = $this->seedProduct('SKU-FS-AUDIT-NONE');
        $client = $this->authenticatedClient();

        $response = $client->request('GET', '/api/objects/'.$product->getId()->toRfc4122().'/form-schema');

        self::assertSame(200, $response->getStatusCode());
        $payload = $response->toArray();
        self::assertIsArray($payload['effectiveGroups']);
        $codes = $this->collectAttributeCodes($payload['effectiveGroups']);
        foreach (['created_at', 'updated_at', 'created_by', 'updated_by'] as $systemCode) {
            self::assertNotContains(
                $systemCode,
                $codes,
                \sprintf('Form-schema must not surface system attribute "%s" without user opt-in.', $systemCode),
            );
        }
    }

    #[Test]
    public function systemAttributesRenderInFormSchemaWhenUserAttachesThem(): void
    {
        // #1077 AC3: once the user explicitly puts a system attribute into a
        // group attached to the ObjectType, the form-schema renders it like
        // any other attribute — no special-casing, no auto-hiding.
        $this->seedSystemAttributes();

        $product = $this->seedProduct('SKU-FS-AUDIT-OPTIN');
        $this->attachSystemAttributeToProductTypeGroup('user-audit', 'User audit');

        $client = $this->authenticatedClient();
        $response = $client->request('GET', '/api/objects/'.$product->getId()->toRfc4122().'/form-schema');

        self::assertSame(200, $response->getStatusCode());
        $payload = $response->toArray();
        self::assertIsArray($payload['effectiveGroups']);
        $codes = $this->collectAttributeCodes($payload['effectiveGroups']);
        self::assertContains(
            'created_at',
            $codes,
            'Form-schema must include `created_at` once user attaches it to an ObjectType group.',
        );
    }

    #[Test]
    public function getFormSchemaRequiresAuthentication(): void
    {
        $product = $this->seedProduct('SKU-FS-002');
        $client = static::createClient();

        $response = $client->request('GET', '/api/objects/'.$product->getId()->toRfc4122().'/form-schema');

        self::assertSame(401, $response->getStatusCode());
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

    private function attachGroupToProductType(string $code, string $label): void
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert(null !== $tenant);

        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($tenant);

        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $type);

        $group = new AttributeGroup($code, ['en' => $label]);
        $attribute = new Attribute($code.'_field', ['en' => $label.' field'], AttributeType::Text);
        $em = $this->em();
        $em->persist($group);
        $em->persist($attribute);
        $em->flush();
        $em->persist(new AttributeGroupAttribute($group, $attribute, 1));
        $em->persist(new ObjectTypeAttributeGroup($type, $group, 1));
        $em->flush();

        $tenantContext->clear();
    }

    private function seedSystemAttributes(): void
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert(null !== $tenant);
        self::getContainer()->get(BuiltInSystemAttributesSeeder::class)->seed($tenant);
    }

    private function attachSystemAttributeToProductTypeGroup(string $groupCode, string $groupLabel): void
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert(null !== $tenant);

        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($tenant);

        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $type);

        $createdAt = self::getContainer()->get(AttributeRepositoryInterface::class)
            ->findByCode('created_at', $tenant);
        \assert(null !== $createdAt, 'created_at must be seeded before this helper.');

        $group = new AttributeGroup($groupCode, ['en' => $groupLabel]);
        $em = $this->em();
        $em->persist($group);
        $em->flush();
        $em->persist(new AttributeGroupAttribute($group, $createdAt, 1));
        $em->persist(new ObjectTypeAttributeGroup($type, $group, 1));
        $em->flush();

        $tenantContext->clear();
    }

    /**
     * @param array<mixed> $groups
     *
     * @return list<string>
     */
    private function collectAttributeCodes(array $groups): array
    {
        $codes = [];
        foreach ($groups as $group) {
            if (!\is_array($group)) {
                continue;
            }
            $attributes = $group['attributes'] ?? [];
            if (!\is_array($attributes)) {
                continue;
            }
            foreach ($attributes as $attribute) {
                if (!\is_array($attribute)) {
                    continue;
                }
                $code = $attribute['code'] ?? null;
                if (\is_string($code)) {
                    $codes[] = $code;
                }
            }
        }

        return $codes;
    }
}
