<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;

/**
 * ULV-03 (#984) — `GET /api/object_types/{id}/list-schema` smoke.
 */
final class ObjectTypeListSchemaApiTest extends CatalogApiTestCase
{
    #[Test]
    public function listSchemaForBuiltInProductReturnsSystemColumns(): void
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert(null !== $tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $productType = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $productType);

        $client = $this->authenticatedClient();
        $response = $client->request('GET', '/api/object_types/'.$productType->getId()->toRfc4122().'/list-schema');

        self::assertSame(200, $response->getStatusCode());
        $payload = $response->toArray();

        self::assertArrayHasKey('objectType', $payload);
        self::assertArrayHasKey('columns', $payload);
        self::assertArrayHasKey('filterableAttributes', $payload);
        self::assertArrayHasKey('searchableAttributes', $payload);

        $objectTypeRow = $payload['objectType'];
        self::assertIsArray($objectTypeRow);
        self::assertSame('product', $objectTypeRow['kind']);
        self::assertSame('product', $objectTypeRow['code']);
        self::assertArrayHasKey('is_categorizable', $objectTypeRow);
        self::assertArrayHasKey('has_variants', $objectTypeRow);

        // Four mandatory system columns.
        $columns = $payload['columns'];
        self::assertIsArray($columns);
        $systemKeys = array_column(
            array_filter($columns, static fn ($c): bool => \is_array($c) && true === ($c['system'] ?? false)),
            'key',
        );
        self::assertContains('code', $systemKeys);
        self::assertContains('status', $systemKeys);
        self::assertContains('completeness', $systemKeys);
        self::assertContains('updatedAt', $systemKeys);
    }

    #[Test]
    public function listSchemaReturnsNotFoundForUnknownId(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request(
            'GET',
            '/api/object_types/01900000-0000-7000-8000-000000000000/list-schema',
        );

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * GRID-P3-01 (#2392) — `?full=1` returns EVERY attached attribute as
     * a column (`default` + `group` flags); without the flag the
     * response keeps the legacy shape (no `default` key, only
     * `show_in_list` columns) and localizable attributes are never
     * sortable (ADR-0028).
     */
    #[Test]
    public function fullModeReturnsWholeCatalogueWithDefaultFlags(): void
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert(null !== $tenant);
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($tenant);

        $productType = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $productType);

        $listed = new Attribute('grid_full_listed', ['en' => 'Listed'], AttributeType::Text);
        $hiddenLoc = new Attribute('grid_full_loc', ['en' => 'Localizable'], AttributeType::Text);
        $hiddenLoc->changeLocalizable(true);

        $em = $this->em();
        $em->persist($listed);
        $em->persist($hiddenLoc);
        $em->persist(new ObjectTypeAttribute($productType, $listed, true, 5));
        $em->persist(new ObjectTypeAttribute($productType, $hiddenLoc, false, 6));
        $em->flush();
        $tenantContext->clear();

        $client = $this->authenticatedClient();
        $base = '/api/object_types/'.$productType->getId()->toRfc4122().'/list-schema';

        // Legacy mode: only show_in_list columns, no `default` key.
        $legacy = $client->request('GET', $base)->toArray();
        $legacyByKey = array_column($legacy['columns'], null, 'key');
        self::assertArrayHasKey('grid_full_listed', $legacyByKey);
        self::assertArrayNotHasKey('grid_full_loc', $legacyByKey);
        self::assertArrayNotHasKey('default', $legacyByKey['grid_full_listed']);

        // Full mode: whole catalogue with default flags + group projection key.
        $full = $client->request('GET', $base.'?full=1')->toArray();
        $fullByKey = array_column($full['columns'], null, 'key');
        self::assertArrayHasKey('grid_full_listed', $fullByKey);
        self::assertArrayHasKey('grid_full_loc', $fullByKey);
        self::assertTrue($fullByKey['grid_full_listed']['default']);
        self::assertFalse($fullByKey['grid_full_loc']['default']);
        self::assertArrayHasKey('group', $fullByKey['grid_full_loc']);
        // ADR-0028 — localizable attributes are not sortable in any mode.
        self::assertFalse($fullByKey['grid_full_loc']['sortable']);
        self::assertTrue($fullByKey['grid_full_listed']['sortable']);
        // Defaults come first: listed before the non-default attribute.
        $keys = array_column($full['columns'], 'key');
        self::assertLessThan(
            array_search('grid_full_loc', $keys, true),
            array_search('grid_full_listed', $keys, true),
        );
    }
}
