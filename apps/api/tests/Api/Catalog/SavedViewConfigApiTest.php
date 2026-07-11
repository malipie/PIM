<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Domain\Entity\SavedView;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;

/**
 * GRID-P4-01/03 (#2394/#2396) — structured SavedView.config validation,
 * per-ObjectType default independence, and system-view immutability.
 */
final class SavedViewConfigApiTest extends CatalogApiTestCase
{
    #[Test]
    public function acceptsStructuredConfigAndRejectsUnknownKeysAndTypes(): void
    {
        $client = $this->authenticatedClient();

        $ok = $client->request('POST', '/api/saved-views', [
            'json' => [
                'name' => 'Grid config '.bin2hex(random_bytes(3)),
                'resource' => 'products',
                'config' => [
                    'filters' => ['brand' => 'Nike'],
                    'sort' => ['key' => 'price', 'dir' => 'desc'],
                    'columns' => [['key' => 'code'], ['key' => 'brand', 'width' => 200, 'hidden' => false]],
                    'density' => 'compact',
                    'variants_mode' => 'flat',
                    'page_size' => 50,
                ],
            ],
        ]);
        self::assertSame(201, $ok->getStatusCode(), $ok->getContent(false));

        $unknownKey = $client->request('POST', '/api/saved-views', [
            'json' => [
                'name' => 'Bad '.bin2hex(random_bytes(3)),
                'config' => ['totally_unknown' => 1],
            ],
        ]);
        self::assertSame(400, $unknownKey->getStatusCode());

        $badSort = $client->request('POST', '/api/saved-views', [
            'json' => [
                'name' => 'Bad sort '.bin2hex(random_bytes(3)),
                'config' => ['sort' => ['key' => 'price', 'dir' => 'sideways']],
            ],
        ]);
        self::assertSame(400, $badSort->getStatusCode());

        $badColumn = $client->request('POST', '/api/saved-views', [
            'json' => [
                'name' => 'Bad col '.bin2hex(random_bytes(3)),
                'config' => ['columns' => [['width' => 100]]],
            ],
        ]);
        self::assertSame(400, $badColumn->getStatusCode());
    }

    #[Test]
    public function legacyConfigWithoutColumnsStillValidates(): void
    {
        $client = $this->authenticatedClient();

        // The shape the FE shipped before GRID (filters + variants_mode +
        // page_size, no columns/sort/density) must keep working.
        $legacy = $client->request('POST', '/api/saved-views', [
            'json' => [
                'name' => 'Legacy '.bin2hex(random_bytes(3)),
                'config' => ['filters' => ['status' => 'published'], 'variants_mode' => 'tree', 'page_size' => 20],
            ],
        ]);
        self::assertSame(201, $legacy->getStatusCode(), $legacy->getContent(false));
    }

    #[Test]
    public function defaultIsIndependentPerObjectType(): void
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        $this->getContainer()->get(TenantContext::class)->set($tenant);
        $repo = $this->getContainer()->get(ObjectTypeRepositoryInterface::class);
        $product = $repo->findBuiltInByKind(ObjectKind::Product, $tenant);
        $category = $repo->findBuiltInByKind(ObjectKind::Category, $tenant);
        \assert(null !== $product && null !== $category);
        $this->getContainer()->get(TenantContext::class)->clear();

        $client = $this->authenticatedClient();

        $productView = $client->request('POST', '/api/saved-views', [
            'json' => [
                'name' => 'Prod default '.bin2hex(random_bytes(3)),
                'resource' => 'products',
                'object_type_id' => $product->getId()->toRfc4122(),
                'is_default' => true,
                'config' => [],
            ],
        ])->toArray();

        $categoryView = $client->request('POST', '/api/saved-views', [
            'json' => [
                'name' => 'Cat default '.bin2hex(random_bytes(3)),
                'resource' => 'products',
                'object_type_id' => $category->getId()->toRfc4122(),
                'is_default' => true,
                'config' => [],
            ],
        ])->toArray();

        // Setting the category default must NOT clear the product default —
        // they are scoped by object_type_id. Re-fetch via the list.
        $byId = $this->indexViews($client->request('GET', '/api/saved-views?resource=products')->toArray());
        $productId = $productView['id'];
        $categoryId = $categoryView['id'];
        self::assertIsString($productId);
        self::assertIsString($categoryId);
        self::assertTrue($byId[$productId]['is_default'] ?? null, 'product default survived');
        self::assertTrue($byId[$categoryId]['is_default'] ?? null, 'category default set');
        self::assertSame($product->getId()->toRfc4122(), $byId[$productId]['object_type_id'] ?? null);
    }

    /**
     * @param array<mixed> $payload
     *
     * @return array<string, array<mixed>>
     */
    private function indexViews(array $payload): array
    {
        $views = $payload['views'] ?? [];
        self::assertIsArray($views);
        $byId = [];
        foreach ($views as $view) {
            if (\is_array($view) && \is_string($view['id'] ?? null)) {
                $byId[$view['id']] = $view;
            }
        }

        return $byId;
    }

    #[Test]
    public function systemViewsCannotBeModifiedOrDeleted(): void
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        $this->getContainer()->get(TenantContext::class)->set($tenant);

        // Seed a system view (user_id IS NULL) directly.
        $system = new SavedView(
            slug: 'system-'.bin2hex(random_bytes(3)),
            name: 'System Default',
            resource: 'products',
            config: [],
            userId: null,
        );
        $system->assignTenant($tenant);
        $em = $this->em();
        $em->persist($system);
        $em->flush();
        $id = $system->getId()->toRfc4122();
        $this->getContainer()->get(TenantContext::class)->clear();

        $client = $this->authenticatedClient();

        $patch = $client->request('PATCH', '/api/saved-views/'.$id, [
            'json' => ['name' => 'hacked'],
        ]);
        self::assertSame(403, $patch->getStatusCode());

        $delete = $client->request('DELETE', '/api/saved-views/'.$id);
        self::assertSame(403, $delete->getStatusCode());
    }
}
