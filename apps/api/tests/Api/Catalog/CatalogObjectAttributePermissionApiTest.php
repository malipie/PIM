<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Identity\Domain\Entity\RoleAttributePermission;
use App\Identity\Domain\Repository\RoleAttributePermissionRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;

/**
 * AUD-008 (#1578) — 3-state attribute permissions MUST be enforced on the
 * DATA path, not only in the form/list schema (PRD §3.5).
 *
 * Scenario: the caller clears the broad gate (`products.view`/`.edit`) but
 * carries a per-attribute `restricted` (or `view`) grant on a sensitive
 * attribute (e.g. purchase price). It must NOT be able to
 *   (a) read the value through `GET /api/products/{id}`,
 *   (b) modify it through PATCH (→ 403),
 * while attributes it CAN see/edit keep working unchanged.
 *
 * The grant is attached to EVERY role the test admin carries. The policy
 * resolves a user's attribute permission as the most-permissive across all
 * roles (PRD §3.5), so a single-role override would be masked by another
 * role's default (`super_admin` defaults every attribute to `edit`).
 * Restricting on all roles makes the override the effective permission while
 * leaving the broad PRD §3.2 gate intact — so any 403/omission is the
 * per-attribute policy doing its job, not the endpoint guard.
 */
final class CatalogObjectAttributePermissionApiTest extends CatalogApiTestCase
{
    #[Test]
    public function getOmitsAttributeWhenCallerHasRestrictedGrant(): void
    {
        $this->seedAttribute('purchase_price', AttributeType::Number);
        $this->seedAttribute('color', AttributeType::Text);

        $client = $this->authenticatedClient();
        $id = $this->createProduct($client, 'AUD008-GET', [
            'purchase_price' => 19.99,
            'color' => 'red',
        ]);

        $this->grantAttributePermission('purchase_price', RoleAttributePermission::LEVEL_RESTRICTED);

        $body = $client->request('GET', '/api/products/'.$id)->toArray();
        $cache = $body['attributesIndexed'] ?? [];
        \assert(\is_array($cache));

        self::assertArrayNotHasKey(
            'purchase_price',
            $cache,
            'restricted attribute must be removed from the GET response (PRD §3.5)',
        );
        // The visible attribute is untouched.
        self::assertSame(['value' => 'red', 'provenance' => 'manual'], $cache['color'] ?? null);
    }

    #[Test]
    public function getKeepsAttributeWhenCallerHasViewGrant(): void
    {
        $this->seedAttribute('purchase_price', AttributeType::Number);

        $client = $this->authenticatedClient();
        $id = $this->createProduct($client, 'AUD008-VIEW', ['purchase_price' => 42.5]);

        // `view` grant: still readable, just not editable.
        $this->grantAttributePermission('purchase_price', RoleAttributePermission::LEVEL_VIEW);

        $body = $client->request('GET', '/api/products/'.$id)->toArray();
        $cache = $body['attributesIndexed'] ?? [];
        \assert(\is_array($cache));

        self::assertSame(['value' => 42.5, 'provenance' => 'manual'], $cache['purchase_price'] ?? null);
    }

    #[Test]
    public function patchRejectsEditOfAttributeWhenCallerHasViewGrant(): void
    {
        $this->seedAttribute('purchase_price', AttributeType::Number);
        $this->seedAttribute('color', AttributeType::Text);

        $client = $this->authenticatedClient();
        $id = $this->createProduct($client, 'AUD008-PATCH', [
            'purchase_price' => 10.5,
            'color' => 'red',
        ]);

        // `view`: read-only — editing must be rejected with 403.
        $this->grantAttributePermission('purchase_price', RoleAttributePermission::LEVEL_VIEW);

        $client->request('PATCH', '/api/products/'.$id, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'body' => json_encode([
                'attributes' => ['purchase_price' => 999.0],
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
            'editing a view-only attribute must be forbidden (PRD §3.5)',
        );

        // The write never landed. Read the canonical value back via a fresh
        // request with the grant lifted so the read itself is not masked.
        $this->liftAttributePermission('purchase_price');
        $body = $this->authenticatedClient()->request('GET', '/api/products/'.$id)->toArray();
        $cache = $body['attributesIndexed'] ?? [];
        \assert(\is_array($cache));
        self::assertSame(['value' => 10.5, 'provenance' => 'manual'], $cache['purchase_price'] ?? null);
    }

    #[Test]
    public function patchAllowsEditOfAttributeStillEditable(): void
    {
        $this->seedAttribute('purchase_price', AttributeType::Number);
        $this->seedAttribute('color', AttributeType::Text);

        $client = $this->authenticatedClient();
        $id = $this->createProduct($client, 'AUD008-OK', [
            'purchase_price' => 10.5,
            'color' => 'red',
        ]);

        // purchase_price restricted to view, but `color` keeps the role
        // default (edit) — editing it must still succeed.
        $this->grantAttributePermission('purchase_price', RoleAttributePermission::LEVEL_VIEW);

        $client->request('PATCH', '/api/products/'.$id, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'body' => json_encode([
                'attributes' => ['color' => 'blue'],
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createProduct(\ApiPlatform\Symfony\Bundle\Test\Client $client, string $code, array $attributes): string
    {
        $created = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => $code,
                'objectTypeId' => $this->objectTypeIdFor(ObjectKind::Product),
                'attributes' => $attributes,
            ], JSON_THROW_ON_ERROR),
        ])->toArray();

        $id = $created['id'] ?? null;
        \assert(\is_string($id));

        return $id;
    }

    private function seedAttribute(string $code, AttributeType $type): Uuid
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        self::getContainer()->get(TenantContext::class)->set($tenant);

        $attribute = new Attribute($code, ['en' => ucfirst($code)], $type);
        self::getContainer()->get(AttributeRepositoryInterface::class)->save($attribute);

        return $attribute->getId();
    }

    /**
     * Grant the given level on the attribute for EVERY role the admin holds,
     * so the most-permissive merge across roles resolves to that level.
     */
    private function grantAttributePermission(string $attributeCode, string $level): void
    {
        $attributeId = $this->attributeId($attributeCode);
        $repo = self::getContainer()->get(RoleAttributePermissionRepositoryInterface::class);

        foreach ($this->adminRoleIds() as $roleId) {
            $existing = $repo->findByRoleAndAttribute($roleId, $attributeId);
            if (null !== $existing) {
                $existing->setPermissionLevel($level);
                $repo->save($existing);

                continue;
            }
            $repo->save(new RoleAttributePermission($roleId, $attributeId, $level));
        }
        $this->em()->flush();
    }

    private function liftAttributePermission(string $attributeCode): void
    {
        $attributeId = $this->attributeId($attributeCode);
        $repo = self::getContainer()->get(RoleAttributePermissionRepositoryInterface::class);

        foreach ($this->adminRoleIds() as $roleId) {
            $existing = $repo->findByRoleAndAttribute($roleId, $attributeId);
            if (null !== $existing) {
                $repo->remove($existing);
            }
        }
        $this->em()->flush();
    }

    private function attributeId(string $attributeCode): Uuid
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $attribute = self::getContainer()->get(AttributeRepositoryInterface::class)
            ->findByCode($attributeCode, $tenant);
        \assert(null !== $attribute);

        return $attribute->getId();
    }

    /**
     * @return list<Uuid>
     */
    private function adminRoleIds(): array
    {
        $user = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail(self::ADMIN_EMAIL);
        \assert(null !== $user);

        $ids = [];
        foreach ($user->getAssignedRoles() as $role) {
            $ids[] = $role->getId();
        }
        \assert([] !== $ids);

        return $ids;
    }
}
