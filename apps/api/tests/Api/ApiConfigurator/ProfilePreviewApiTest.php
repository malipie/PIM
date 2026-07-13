<?php

declare(strict_types=1);

namespace App\Tests\Api\ApiConfigurator;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;

/**
 * #2550 — the live profile preview endpoint. Takes a draft (unsaved) profile
 * config and returns a bounded sample of REAL objects, scoped by the filters +
 * ObjectType list and projected to the attribute allow-list — exactly what a
 * partner integration would receive over the API.
 */
final class ProfilePreviewApiTest extends ApiConfiguratorApiTestCase
{
    #[Test]
    public function previewReturnsScopedAndProjectedRowsForADraftConfig(): void
    {
        $productType = $this->seedProducts();

        $client = $this->authenticatedClient();
        $response = $client->request('POST', '/api/profiles/preview', [
            'json' => [
                'objectTypeIds' => [$productType->getId()->toRfc4122()],
                'filters' => ['status' => 'published'],
                'includedAttributes' => ['name'],
                'limit' => 5,
            ],
        ]);

        self::assertResponseIsSuccessful();
        /** @var array{items: list<array{code: string, attributes: array<string, mixed>}>, count: int} $body */
        $body = $response->toArray();
        $items = $body['items'];
        $codes = array_column($items, 'code');
        self::assertContains('PUB-1', $codes, 'published object is in scope');
        self::assertNotContains('DRAFT-1', $codes, 'draft must not leak into the preview');

        // Field projection: only the allow-listed `name` survives; `brand` is dropped.
        $published = null;
        foreach ($items as $item) {
            if ('PUB-1' === $item['code']) {
                $published = $item;
            }
        }
        self::assertNotNull($published);
        self::assertSame(['name'], array_keys($published['attributes']));
        self::assertSame('Widget', $published['attributes']['name']);
    }

    #[Test]
    public function previewIsEmptyWhenNothingMatchesTheFilter(): void
    {
        $productType = $this->seedProducts();

        $client = $this->authenticatedClient();
        $response = $client->request('POST', '/api/profiles/preview', [
            'json' => [
                'objectTypeIds' => [$productType->getId()->toRfc4122()],
                'filters' => ['status' => 'archived'],
            ],
        ]);

        self::assertResponseIsSuccessful();
        /** @var array{items: list<mixed>, count: int} $body */
        $body = $response->toArray();
        self::assertSame(0, $body['count']);
        self::assertSame([], $body['items']);
    }

    private function seedProducts(): ObjectType
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $productType = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt', 'en' => 'Product']);
        $productType->assignTenant($tenant);
        $em->persist($productType);

        $published = new CatalogObject($productType, 'PUB-1');
        $published->assignTenant($tenant);
        $published->forceStatus(CatalogObject::STATUS_PUBLISHED);
        $published->updateAttributeIndex(['name' => 'Widget', 'brand' => 'Festo']);
        $em->persist($published);

        $draft = new CatalogObject($productType, 'DRAFT-1');
        $draft->assignTenant($tenant);
        $draft->updateAttributeIndex(['name' => 'Draft widget']);
        $em->persist($draft);

        $em->flush();

        return $productType;
    }
}
