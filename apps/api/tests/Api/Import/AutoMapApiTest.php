<?php

declare(strict_types=1);

namespace App\Tests\Api\Import;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use PHPUnit\Framework\Attributes\Test;

use const JSON_THROW_ON_ERROR;

/**
 * IMP-02 (#443) ApiTestCase — wizard Step 2 round-trip.
 *
 * Anchor case from spec §5.3: 15 festo headers → 12 auto-recognised
 * + 3 manual. Test seeds the 12 attributes + binds them to the
 * built-in product ObjectType so the matcher's "available attributes"
 * filter accepts every dictionary-driven hit.
 */
final class AutoMapApiTest extends CatalogApiTestCase
{
    #[Test]
    public function festoHeadersResolveToTwelveOutOfFifteenSuggestions(): void
    {
        $this->seedProductAttributes();

        $client = $this->authenticatedClient();
        $response = $client->request('POST', '/api/import-sessions/auto-map', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode([
                'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                'column_headers' => [
                    'Kod produktu', 'Nazwa', 'Cena netto', 'Producent', 'Kategoria',
                    'EAN', 'Description', 'image_1', 'image_2', 'image_3',
                    'IP_class', 'Numer Festo', 'Srednica zewn.', 'Stara cena', 'Notatki wewn.',
                ],
                'sample_values' => [
                    ['FESTO-1', 'Czujnik X-200', '245.50', 'Festo', 'Pneumatyka', '5901234567890', 'Czujnik', 'a.jpg', 'b.jpg', 'c.jpg', 'IP67', 'ABC-987', '12.5 mm', '299.00', 'Promo'],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        $mappings = $body['mappings'] ?? null;
        self::assertIsArray($mappings);
        self::assertCount(15, $mappings);

        $auto = array_filter($mappings, static fn ($row): bool => \is_array($row) && 'auto' === ($row['confidence'] ?? null));
        $manual = array_filter($mappings, static fn ($row): bool => \is_array($row) && 'manual' === ($row['confidence'] ?? null));

        self::assertCount(12, $auto, 'Spec §5.3 anchor: 12/15 auto-mapped on the festo fixture.');
        self::assertCount(3, $manual);
    }

    #[Test]
    public function rejectsRequestWithoutColumnHeaders(): void
    {
        $client = $this->authenticatedClient();
        $client->request('POST', '/api/import-sessions/auto-map', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode([
                'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                'column_headers' => [],
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function rejectsUnknownObjectTypeWith404(): void
    {
        $client = $this->authenticatedClient();
        $client->request('POST', '/api/import-sessions/auto-map', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode([
                'target_object_type_id' => '01943fd0-0000-7000-0000-000000000000',
                'column_headers' => ['sku'],
                'sample_values' => [],
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function dictionaryEndpointReturnsMappedAttributes(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request('GET', '/api/import-sessions/dictionary');

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        $attributes = $body['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertArrayHasKey('sku', $attributes);
        self::assertIsArray($attributes['sku']);
        self::assertContains('kodproduktu', $attributes['sku']);
    }

    private function seedProductAttributes(): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $product = self::getContainer()
            ->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($product instanceof ObjectType);

        $attributes = [
            'sku' => AttributeType::Text,
            'name' => AttributeType::Text,
            'price' => AttributeType::Number,
            'brand' => AttributeType::Text,
            'category' => AttributeType::Text,
            'ean' => AttributeType::Text,
            'description' => AttributeType::Text,
            'main_image' => AttributeType::Text,
            'gallery_2' => AttributeType::Text,
            'gallery_3' => AttributeType::Text,
            'ip_class' => AttributeType::Text,
            'diameter' => AttributeType::Text,
        ];

        $position = 1;
        foreach ($attributes as $code => $type) {
            $attribute = new Attribute($code, ['en' => ucfirst(str_replace('_', ' ', $code))], $type);
            $em->persist($attribute);
            $em->persist(new ObjectTypeAttribute($product, $attribute, false, $position++));
        }
        $em->flush();
    }
}
