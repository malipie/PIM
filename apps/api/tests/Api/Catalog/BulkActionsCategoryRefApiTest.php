<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;

use const JSON_THROW_ON_ERROR;

/**
 * #2161 — the Cmd+K planner emits human-friendly category_codes
 * ("dodaj kategorię botki"), not UUIDs. The bulk-actions add_category
 * endpoint resolves each reference by code OR display name (so the quick
 * action actually applies), and an unknown reference is a clear 404 rather
 * than the old cryptic "category_ids must be a non-empty array" 400.
 */
final class BulkActionsCategoryRefApiTest extends CatalogApiTestCase
{
    #[Test]
    public function addCategoryResolvesCategoryCodesByDisplayName(): void
    {
        $product = $this->seedObject(ObjectKind::Product, 'PROD-CATREF-1', []);
        // Category whose CODE is "buty" but whose NAME is "Botki" — the user
        // types the name, the planner passes it through as a code.
        $this->seedObject(ObjectKind::Category, 'buty', ['name' => ['value' => 'Botki']]);

        $client = $this->authenticatedClient();
        $client->request('POST', '/api/products/bulk-actions/add_category', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode([
                'target_ids' => [$product],
                'payload' => ['category_codes' => ['botki']],
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function addCategoryResolvesByCode(): void
    {
        $product = $this->seedObject(ObjectKind::Product, 'PROD-CATREF-2', []);
        $this->seedObject(ObjectKind::Category, 'CAT-FOOTWEAR', ['name' => ['value' => 'Obuwie']]);

        $client = $this->authenticatedClient();
        $client->request('POST', '/api/products/bulk-actions/add_category', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode([
                'target_ids' => [$product],
                'payload' => ['category_codes' => ['cat-footwear']],
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function addCategoryWithUnknownReferenceIsA404(): void
    {
        $product = $this->seedObject(ObjectKind::Product, 'PROD-CATREF-3', []);

        $client = $this->authenticatedClient();
        $client->request('POST', '/api/products/bulk-actions/add_category', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode([
                'target_ids' => [$product],
                'payload' => ['category_codes' => ['nieistniejaca-kategoria']],
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, mixed> $indexed
     */
    private function seedObject(ObjectKind $kind, string $code, array $indexed): string
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)->findBuiltInByKind($kind, $tenant);
        \assert(null !== $type);

        $object = new CatalogObject($type, $code);
        if ([] !== $indexed) {
            $object->updateAttributeIndex($indexed);
        }
        $em->persist($object);
        $em->flush();

        return $object->getId()->toRfc4122();
    }
}
