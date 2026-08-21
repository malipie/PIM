<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Application\ObjectTypeService;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Exception\DisabledFeatureException;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeAttributeRepositoryInterface;
use App\Catalog\Infrastructure\ApiPlatform\CustomObjectTypeApiGuard;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;

use const JSON_THROW_ON_ERROR;

/**
 * End-to-end coverage for the `pim.catalog.enable_custom_object_types`
 * feature flag (audit finding HIGH-001 / 2026-04-29).
 *
 * Custom ObjectType creation is gated by **three layers** by design
 * (`Project Plan/01-architektura-pim.md` §13, ADR-009 / R-29):
 *  1. {@see CustomObjectTypeApiGuard} — API-edge defence,
 *  2. {@see ObjectTypeService} — service-level enforcement,
 *  3. DB CHECK constraint allows `kind='custom'` rows so phase 2/3
 *     unlock is a flag flip, not a migration.
 *
 * Existing tests cover layers 1 + 2 in isolation
 * ({@see \App\Tests\Unit\Catalog\Infrastructure\ApiPlatform\CustomObjectTypeApiGuardTest},
 * {@see \App\Tests\Integration\Catalog\ObjectTypeServiceTest}).
 *
 * This test wires the layers together through the live HTTP surface:
 *   - confirms the guard and the service both throw on flag OFF,
 *   - confirms the service succeeds on flag ON and the row reaches
 *     `/api/object_types`,
 *   - confirms the catalog write path (`POST /api/products`) refuses
 *     a `custom` ObjectType id with 422 (kind mismatch — defence in
 *     depth in case the listing surface ever leaks a custom row).
 */
final class CustomKindFeatureFlagApiTest extends CatalogApiTestCase
{
    #[Test]
    public function apiGuardThrowsWhenFlagDisabled(): void
    {
        // The parameter default flipped to ON in 2026-05-01 (UI-02 follow-up:
        // modeling UI now exposes a Create custom ObjectType flow). The guard
        // contract itself stays unchanged — operators that override
        // `CATALOG_ENABLE_CUSTOM_OBJECT_TYPES=false` per environment must
        // still see custom-kind writes blocked at the API edge.
        $guard = new CustomObjectTypeApiGuard(false);

        $this->expectException(DisabledFeatureException::class);
        $guard->assertAllowed(ObjectKind::Custom);
    }

    #[Test]
    public function serviceThrowsWhenFlagDisabled(): void
    {
        $service = $this->serviceForFlag(false);

        $this->expectException(DisabledFeatureException::class);
        $service->create('shoes', ObjectKind::Custom, ['en' => 'Shoes']);
    }

    #[Test]
    public function serviceSucceedsWhenFlagEnabled(): void
    {
        $type = $this->serviceForFlag(true)->create(
            'shoes',
            ObjectKind::Custom,
            ['en' => 'Shoes'],
        );

        self::assertSame(ObjectKind::Custom, $type->getKind());

        // Persisted custom kind reaches the public listing — schema is
        // forward-compatible per ADR-009. Listing visibility intentionally
        // does NOT depend on the flag (the flag only gates creation).
        $client = $this->authenticatedClient();
        $body = $client->request('GET', '/api/object_types')->toArray();
        $members = $body['member'] ?? $body['hydra:member'] ?? null;
        \assert(\is_array($members));

        $codes = array_column($members, 'code');
        self::assertContains('shoes', $codes);
    }

    #[Test]
    public function postProductWithCustomKindObjectTypeReturns422(): void
    {
        // Bypass the guard via a direct ObjectType constructor + persist
        // — simulates a tenant who once toggled the flag, created a row,
        // then toggled back. The DB row is real; only the user-facing
        // write surface remains gated.
        $tenant = $this->tenantByCode(self::TENANT_CODE);
        $customType = new ObjectType(
            code: 'shoes',
            kind: ObjectKind::Custom,
            label: ['en' => 'Shoes'],
        );
        $customType->assignTenant($tenant);
        $em = $this->em();
        $em->persist($customType);
        $em->flush();

        $client = $this->authenticatedClient();
        $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'SKU-FAKE',
                'objectTypeId' => $customType->getId()->toRfc4122(),
            ], JSON_THROW_ON_ERROR),
        ]);

        // CreateCatalogObjectHandler asserts `objectType.kind == expectedKind`
        // (`product` for /api/products). Custom kind on a product write =
        // 422 — the public write path stays kind-clean even with custom
        // rows in the DB.
        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function customKindObjectIsNotListedWhenAbsent(): void
    {
        // Sanity: a fresh tenant with only built-in seed rows must not
        // expose a `custom` kind on the listing. Regression guard for
        // a future seeder that accidentally seeds `kind=custom`.
        $client = $this->authenticatedClient();
        $body = $client->request('GET', '/api/object_types')->toArray();
        $members = $body['member'] ?? $body['hydra:member'] ?? null;
        \assert(\is_array($members));

        $kinds = array_column($members, 'kind');
        self::assertNotContains('custom', $kinds);
    }

    private function serviceForFlag(bool $flag): ObjectTypeService
    {
        // Service-level calls bypass the request lifecycle, so we have to
        // bind the tenant context manually — request-driven tests pick it
        // up from the JWT principal in the firewall listener.
        self::getContainer()->get(TenantContext::class)
            ->set($this->tenantByCode(self::TENANT_CODE));

        return new ObjectTypeService(
            $this->em(),
            self::getContainer()->get(ObjectTypeAttributeRepositoryInterface::class),
            self::getContainer()->get(\App\Catalog\Domain\Repository\AttributeRepositoryInterface::class),
            self::getContainer()->get(\Doctrine\DBAL\Connection::class),
            self::getContainer()->get(TenantContext::class),
            $flag,
        );
    }

    private function tenantByCode(string $code): Tenant
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => $code]);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }
}
