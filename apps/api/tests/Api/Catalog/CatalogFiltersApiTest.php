<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;

/**
 * Coverage for #43 (0.4.3) — custom AP4 filters on the catalog
 * sugar paths.
 *
 * Each test seeds a small fixture of CatalogObject rows then hits
 * `/api/products` (or `/api/categories`) with the matching filter
 * to assert the row count + identity of the matched member set.
 *
 * The seed runs after the parent setUp() (RBAC + tenant + admin user)
 * so the standard `authenticatedClient()` already has a JWT in scope.
 */
final class CatalogFiltersApiTest extends CatalogApiTestCase
{
    #[Test]
    public function skuFilterMatchesSubstring(): void
    {
        $this->seedProducts(['ALPHA-1', 'ALPHA-2', 'BETA-1']);

        $client = $this->authenticatedClient();
        $body = $client->request('GET', '/api/products?sku=alpha')->toArray();

        $codes = $this->codesFrom($body);
        self::assertCount(2, $codes);
        self::assertContains('ALPHA-1', $codes);
        self::assertContains('ALPHA-2', $codes);
    }

    #[Test]
    public function parentIdFilterReturnsOnlyDirectChildren(): void
    {
        // 1 master + 3 variants of master + 2 unrelated products.
        $em = $this->em();
        $tenant = $this->tenant();
        $context = self::getContainer()->get(TenantContext::class);
        $context->set($tenant);

        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $type);

        $repo = self::getContainer()->get(CatalogObjectRepositoryInterface::class);
        $master = new CatalogObject($type, 'PARENT-MASTER');
        $repo->save($master);
        foreach (['PARENT-VARIANT-A', 'PARENT-VARIANT-B', 'PARENT-VARIANT-C'] as $code) {
            $variant = new CatalogObject($type, $code);
            $variant->assignParent($master);
            $repo->save($variant);
        }
        $unrelatedA = new CatalogObject($type, 'UNRELATED-A');
        $repo->save($unrelatedA);
        $unrelatedB = new CatalogObject($type, 'UNRELATED-B');
        $repo->save($unrelatedB);
        $em->flush();
        $em->clear();

        $client = $this->authenticatedClient();
        $body = $client
            ->request('GET', \sprintf('/api/products?parent_id=%s', $master->getId()->toRfc4122()))
            ->toArray();

        $codes = $this->codesFrom($body);
        self::assertCount(3, $codes);
        self::assertContains('PARENT-VARIANT-A', $codes);
        self::assertContains('PARENT-VARIANT-B', $codes);
        self::assertContains('PARENT-VARIANT-C', $codes);
        self::assertNotContains('PARENT-MASTER', $codes);
        self::assertNotContains('UNRELATED-A', $codes);
    }

    #[Test]
    public function parentIdFilterNullReturnsMastersOnly(): void
    {
        // 1 master + 2 variants — the masters-only filter must hide
        // the variants and surface the master so the products list
        // tree mode (#514) can paginate masters without losing them
        // to a variant-heavy page.
        $em = $this->em();
        $tenant = $this->tenant();
        $context = self::getContainer()->get(TenantContext::class);
        $context->set($tenant);

        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $type);

        $repo = self::getContainer()->get(CatalogObjectRepositoryInterface::class);
        $master = new CatalogObject($type, 'MASTERS-ONLY-MASTER');
        $repo->save($master);
        foreach (['MASTERS-ONLY-V1', 'MASTERS-ONLY-V2'] as $code) {
            $variant = new CatalogObject($type, $code);
            $variant->assignParent($master);
            $repo->save($variant);
        }
        $em->flush();
        $em->clear();

        $client = $this->authenticatedClient();
        $body = $client->request('GET', '/api/products?parent_id=null')->toArray();

        $codes = $this->codesFrom($body);
        self::assertContains('MASTERS-ONLY-MASTER', $codes);
        self::assertNotContains('MASTERS-ONLY-V1', $codes);
        self::assertNotContains('MASTERS-ONLY-V2', $codes);
    }

    #[Test]
    public function parentIdFilterIgnoresInvalidUuid(): void
    {
        $this->seedProducts(['INVALID-PARENT-A', 'INVALID-PARENT-B']);

        $client = $this->authenticatedClient();
        // Non-UUID — filter is a no-op so the unfiltered list still
        // contains both seed rows. Keeps the FE robust against stale
        // master ids in URL state.
        $body = $client->request('GET', '/api/products?parent_id=not-a-uuid')->toArray();

        self::assertGreaterThanOrEqual(2, $body['totalItems'] ?? 0);
    }

    #[Test]
    public function statusFilterRespectsEnumWhitelist(): void
    {
        $this->seedProducts(['SKU-A', 'SKU-B'], status: CatalogObject::STATUS_PUBLISHED);
        $this->seedProducts(['SKU-DRAFT'], status: CatalogObject::STATUS_DRAFT);

        $client = $this->authenticatedClient();
        $body = $client->request('GET', '/api/products?status=published')->toArray();

        $codes = $this->codesFrom($body);
        self::assertCount(2, $codes);
        self::assertContains('SKU-A', $codes);
        self::assertContains('SKU-B', $codes);
    }

    #[Test]
    public function statusFilterIgnoresInvalidEnumValue(): void
    {
        $this->seedProducts(['SKU-X', 'SKU-Y']);

        $client = $this->authenticatedClient();
        // `published_xxx` is not in the enum — filter should fall through
        // to "no constraint applied" rather than fabricate one.
        $body = $client->request('GET', '/api/products?status=published_xxx')->toArray();

        self::assertGreaterThanOrEqual(2, $body['totalItems'] ?? 0);
    }

    #[Test]
    public function attributeFilterMatchesJsonbContainment(): void
    {
        $this->seedProducts(['NIKE-RED'], attributes: ['brand' => 'Nike', 'color' => 'red']);
        $this->seedProducts(['ADIDAS-BLUE'], attributes: ['brand' => 'Adidas', 'color' => 'blue']);
        $this->seedProducts(['NIKE-BLUE'], attributes: ['brand' => 'Nike', 'color' => 'blue']);

        $client = $this->authenticatedClient();
        $body = $client->request('GET', '/api/products?attribute[brand]=Nike')->toArray();

        $codes = $this->codesFrom($body);
        self::assertCount(2, $codes);
        self::assertContains('NIKE-RED', $codes);
        self::assertContains('NIKE-BLUE', $codes);
    }

    #[Test]
    public function attributeFilterAndsMultipleKeys(): void
    {
        $this->seedProducts(['NIKE-RED'], attributes: ['brand' => 'Nike', 'color' => 'red']);
        $this->seedProducts(['NIKE-BLUE'], attributes: ['brand' => 'Nike', 'color' => 'blue']);
        $this->seedProducts(['ADIDAS-RED'], attributes: ['brand' => 'Adidas', 'color' => 'red']);

        $client = $this->authenticatedClient();
        $body = $client->request('GET', '/api/products?attribute[brand]=Nike&attribute[color]=red')->toArray();

        $codes = $this->codesFrom($body);
        self::assertSame(['NIKE-RED'], $codes);
    }

    #[Test]
    public function categoryFilterMatchesDescendants(): void
    {
        $this->seedCategoryTree([
            'electronics' => 'electronics',
            'audio' => 'electronics.audio',
            'phones' => 'electronics.phones',
            'kitchen' => 'kitchen',
        ]);

        $client = $this->authenticatedClient();
        $body = $client->request('GET', '/api/categories?category=electronics')->toArray();

        $codes = $this->codesFrom($body);
        self::assertContains('electronics', $codes);
        self::assertContains('audio', $codes);
        self::assertContains('phones', $codes);
        self::assertNotContains('kitchen', $codes);
    }

    #[Test]
    public function categoryFilterUnknownCodeReturnsEmpty(): void
    {
        $this->seedCategoryTree(['electronics' => 'electronics']);

        $client = $this->authenticatedClient();
        $body = $client->request('GET', '/api/categories?category=does_not_exist')->toArray();

        self::assertSame(0, $body['totalItems'] ?? -1);
    }

    #[Test]
    public function completenessFilterRangeQuery(): void
    {
        // AUD-037: the filter now reads the `completeness_pct` column, which
        // `recordCompleteness()` mirrors from the `global` key (not `pct`).
        $this->seedProducts(['LOW'], completeness: ['global' => 30]);
        $this->seedProducts(['MID'], completeness: ['global' => 70]);
        $this->seedProducts(['HIGH'], completeness: ['global' => 95]);

        $client = $this->authenticatedClient();
        $body = $client->request('GET', '/api/products?completeness[gte]=80')->toArray();

        $codes = $this->codesFrom($body);
        self::assertSame(['HIGH'], $codes);

        $body = $client->request('GET', '/api/products?completeness[gt]=30&completeness[lt]=90')->toArray();
        $codes = $this->codesFrom($body);
        self::assertSame(['MID'], $codes);
    }

    /**
     * @param list<string>          $codes
     * @param array<string, string> $attributes
     * @param array<string, mixed>  $completeness
     */
    private function seedProducts(
        array $codes,
        array $attributes = [],
        array $completeness = [],
        string $status = CatalogObject::STATUS_DRAFT,
    ): void {
        $em = $this->em();
        $tenant = $this->tenant();
        $context = self::getContainer()->get(TenantContext::class);
        $context->set($tenant);

        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $type);

        $repo = self::getContainer()->get(CatalogObjectRepositoryInterface::class);
        foreach ($codes as $code) {
            $object = new CatalogObject($type, $code);
            if (CatalogObject::STATUS_DRAFT !== $status) {
                $object->forceStatus($status);
            }
            if ([] !== $attributes) {
                $object->updateAttributeIndex($attributes);
            }
            if ([] !== $completeness) {
                $object->recordCompleteness($completeness);
            }
            $repo->save($object);
        }
        $em->clear();
    }

    /**
     * @param array<string, string> $codeToPath
     */
    private function seedCategoryTree(array $codeToPath): void
    {
        $em = $this->em();
        $tenant = $this->tenant();
        $context = self::getContainer()->get(TenantContext::class);
        $context->set($tenant);

        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Category, $tenant);
        \assert(null !== $type);

        $repo = self::getContainer()->get(CatalogObjectRepositoryInterface::class);
        foreach ($codeToPath as $code => $path) {
            $object = new CatalogObject($type, $code);
            $object->attachToPath($path);
            $repo->save($object);
        }
        $em->clear();
    }

    /**
     * @param array<int|string, mixed> $body
     *
     * @return list<string>
     */
    private function codesFrom(array $body): array
    {
        $members = $body['member'] ?? $body['hydra:member'] ?? null;
        \assert(\is_array($members));

        $codes = [];
        foreach ($members as $row) {
            \assert(\is_array($row));
            $code = $row['code'] ?? null;
            if (\is_string($code)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    private function tenant(): Tenant
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }
}
