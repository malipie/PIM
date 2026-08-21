<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Catalog\Application\Message\CheckSchemaDriftForCategory;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectCategory;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;

/**
 * CHC-05 (#1287) — move-impact endpoint + confirm gate + drift-check dispatch.
 */
final class CategoryMoveImpactApiTest extends CatalogApiTestCase
{
    #[Test]
    public function moveImpactReturnsShapeForChildlessCategory(): void
    {
        $client = $this->authenticatedClient();
        $root = $this->createCategory($client, 'mi_root');
        $target = $this->createCategory($client, 'mi_target');

        $response = $client->request('GET', "/api/categories/{$root}/move-impact?targetParentId={$target}", [
            'headers' => ['accept' => 'application/json'],
        ]);
        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertSame(0, $body['affectedObjectsCount']);
        self::assertArrayHasKey('schemaWillChange', $body);
        self::assertArrayHasKey('addedGroupLabels', $body);
        self::assertArrayHasKey('removedGroupLabels', $body);
    }

    #[Test]
    public function moveWithoutConfirmReturns409WhenProductsAffected(): void
    {
        $client = $this->authenticatedClient();
        $root = $this->createCategory($client, 'gate_root');
        $child = $this->createCategoryUnder($client, 'gate_child', $root);
        $target = $this->createCategory($client, 'gate_target');
        $this->assignProductTo($child);

        $response = $client->request('PATCH', "/api/categories/{$child}/move", [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['newParentId' => $target], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $response->toArray(false)['affectedObjectsCount'] ?? null);
    }

    #[Test]
    public function moveWithConfirmSucceedsAndDispatchesDriftCheck(): void
    {
        $client = $this->authenticatedClient();
        $root = $this->createCategory($client, 'conf_root');
        $child = $this->createCategoryUnder($client, 'conf_child', $root);
        $target = $this->createCategory($client, 'conf_target');
        $this->assignProductTo($child);

        $response = $client->request('PATCH', "/api/categories/{$child}/move?confirmed=true", [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['newParentId' => $target], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('conf_target.conf_child', $response->toArray()['newPath'] ?? null);
        $dispatched = $this->dispatchedAsyncMessageClasses();
        if (null !== $dispatched) {
            self::assertContains(CheckSchemaDriftForCategory::class, $dispatched);
        }
    }

    #[Test]
    public function moveWithoutProductsSucceedsWithoutConfirm(): void
    {
        $client = $this->authenticatedClient();
        $root = $this->createCategory($client, 'np_root');
        $child = $this->createCategoryUnder($client, 'np_child', $root);
        $target = $this->createCategory($client, 'np_target');

        $response = $client->request('PATCH', "/api/categories/{$child}/move", [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['newParentId' => $target], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(200);
        $dispatched = $this->dispatchedAsyncMessageClasses();
        if (null !== $dispatched) {
            self::assertNotContains(CheckSchemaDriftForCategory::class, $dispatched);
        }
    }

    #[Test]
    public function deleteLeafCategoryWithoutAssignmentsSucceeds(): void
    {
        $client = $this->authenticatedClient();
        $category = $this->createCategory($client, 'delete_leaf');

        $response = $client->request('DELETE', "/api/categories/{$category}");

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function categoryUsageCountsAssignedProducts(): void
    {
        // #2942 — the panel reported 0 products for a category full of them:
        // the counter read `objects.parent_id`, which is the ltree hierarchy
        // pointer, not category membership. Products join through the
        // `object_categories` junction.
        $client = $this->authenticatedClient();
        $category = $this->createCategory($client, 'usage_assigned');
        $this->assignProductTo($category);

        $usage = $client->request('GET', "/api/categories/{$category}/usage", [
            'headers' => ['accept' => 'application/json'],
        ]);

        self::assertSame(1, $usage->toArray()['instanceCount'] ?? null);
    }

    #[Test]
    public function deleteCategoryWithAssignedProductDetachesItRatherThanRefusing(): void
    {
        // PCAT-03 (#476) is deliberate: removing a category cascades its
        // assignments away and `PrimaryCategoryRepairListener` promotes the
        // next one to primary. Pinned here because #2942 asked the UI to warn
        // about this — the operator must see what a delete will detach, and
        // this is the behaviour the warning describes.
        $client = $this->authenticatedClient();
        $category = $this->createCategory($client, 'delete_assigned');
        $this->assignProductTo($category);

        $response = $client->request('DELETE', "/api/categories/{$category}");

        self::assertSame(204, $response->getStatusCode());
        $assignmentCount = $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM object_categories WHERE category_id = CAST(:id AS uuid)',
            ['id' => $category],
        );
        self::assertTrue(\is_int($assignmentCount) || \is_string($assignmentCount));
        self::assertSame(0, (int) $assignmentCount);
    }

    #[Test]
    public function deleteCategoryWithDescendantReturns409(): void
    {
        $client = $this->authenticatedClient();
        $root = $this->createCategory($client, 'delete_root');
        $this->createCategoryUnder($client, 'delete_child', $root);

        $response = $client->request('DELETE', "/api/categories/{$root}");

        self::assertSame(409, $response->getStatusCode());
    }

    /**
     * Dispatched async message classes, or null when the async transport is the
     * `sync://` alias (`.env.test`) where nothing is collectable. CI overrides
     * `MESSENGER_TRANSPORT_DSN=in-memory://`, so the dispatch is asserted there.
     *
     * @return list<class-string>|null
     */
    private function dispatchedAsyncMessageClasses(): ?array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        if (!$transport instanceof InMemoryTransport) {
            return null;
        }

        $classes = [];
        foreach ($transport->getSent() as $envelope) {
            $classes[] = $envelope->getMessage()::class;
        }

        return $classes;
    }

    private function assignProductTo(string $categoryId): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $productType = $em->find(ObjectType::class, Uuid::fromString($this->objectTypeIdFor(ObjectKind::Product)));
        \assert($productType instanceof ObjectType);
        $category = $em->find(CatalogObject::class, Uuid::fromString($categoryId));
        \assert($category instanceof CatalogObject);

        $product = new CatalogObject($productType, 'SKU-CHC05');
        $em->persist($product);
        $em->flush();

        $em->persist(new ObjectCategory($product, $category, true));
        $em->flush();
    }

    private function createCategory(Client $client, string $code): string
    {
        $response = $client->request('POST', '/api/categories', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => $code,
                'objectTypeId' => $this->objectTypeIdFor(ObjectKind::Category),
                'categoryTargetObjectTypeId' => $this->objectTypeIdFor(ObjectKind::Product),
            ], JSON_THROW_ON_ERROR),
        ]);
        $id = $response->toArray()['id'] ?? null;
        \assert(\is_string($id));

        return $id;
    }

    private function createCategoryUnder(Client $client, string $code, string $parentId): string
    {
        $response = $client->request('POST', '/api/categories', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => $code,
                'parentId' => $parentId,
                'objectTypeId' => $this->objectTypeIdFor(ObjectKind::Category),
                'categoryTargetObjectTypeId' => $this->objectTypeIdFor(ObjectKind::Product),
            ], JSON_THROW_ON_ERROR),
        ]);
        $id = $response->toArray()['id'] ?? null;
        \assert(\is_string($id));

        return $id;
    }
}
