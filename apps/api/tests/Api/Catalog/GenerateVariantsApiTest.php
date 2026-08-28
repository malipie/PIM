<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Provenance;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectValueRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;

use const JSON_THROW_ON_ERROR;

/**
 * VIEW-07.3 (#432) — coverage for `POST /api/products/{id}/generate-variants`
 * after master attribute inheritance + axis stamping landed on top of the
 * UI-02.6 (#296) endpoint shape.
 *
 * Asserts:
 *   - Variant created with master's attribute values cloned as ObjectValue
 *     rows (Provenance::Manual) plus axis values stamped on top.
 *   - Unknown axis code (no Attribute on the schema) returns 400 fail-fast.
 *   - SKU collisions on a re-run are reported via skipped_existing without
 *     duplicating ObjectValue rows.
 */
final class GenerateVariantsApiTest extends CatalogApiTestCase
{
    #[Test]
    public function postGenerateVariantsInheritsMasterAttributesAndStampsAxisValues(): void
    {
        $this->seedAttribute('brand', AttributeType::Text);
        $this->seedAttribute('color', AttributeType::Text);
        $this->seedAttribute('size', AttributeType::Text);

        $client = $this->authenticatedClient();

        $master = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'VAR-MASTER-1',
                'objectTypeId' => $this->objectTypeIdFor(ObjectKind::Product),
                'attributes' => ['brand' => 'Acme'],
            ], JSON_THROW_ON_ERROR),
        ])->toArray();

        $masterId = $master['id'] ?? null;
        \assert(\is_string($masterId));

        $response = $client->request('POST', '/api/products/'.$masterId.'/generate-variants', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode([
                'axes' => [
                    'color' => ['red', 'blue'],
                    'size' => ['S'],
                ],
                'sku_template' => '{master_sku}-{color}-{size}',
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(201);
        $body = $response->toArray();
        self::assertSame(2, $body['created_count'] ?? null);
        self::assertSame(0, $body['skipped_count'] ?? null);

        /** @var array<int, array<string, mixed>> $createdRows */
        $createdRows = (array) $body['created'];
        $createdSkus = array_map(
            static function (array $row): string {
                $sku = $row['sku'] ?? null;

                return \is_string($sku) ? $sku : '';
            },
            $createdRows,
        );
        self::assertContains('VAR-MASTER-1-red-S', $createdSkus);
        self::assertContains('VAR-MASTER-1-blue-S', $createdSkus);

        // Inspect one variant: brand inherited + color/size stamped, all manual.
        $variant = $this->reloadObject('VAR-MASTER-1-red-S');
        $values = self::getContainer()->get(ObjectValueRepositoryInterface::class)
            ->findByObject($variant);

        $byCode = [];
        foreach ($values as $value) {
            $byCode[$value->getAttribute()->getCode()] = $value;
        }

        self::assertArrayHasKey('brand', $byCode, 'Brand must be inherited from master.');
        self::assertSame(['value' => 'Acme'], $byCode['brand']->getValue());
        self::assertSame(Provenance::Manual, $byCode['brand']->getProvenance());

        self::assertArrayHasKey('color', $byCode, 'Color axis must be stamped on the variant.');
        self::assertSame(['value' => 'red'], $byCode['color']->getValue());
        self::assertSame(Provenance::Manual, $byCode['color']->getProvenance());

        self::assertArrayHasKey('size', $byCode);
        self::assertSame(['value' => 'S'], $byCode['size']->getValue());
    }

    #[Test]
    public function postGenerateVariantsRejectsUnknownAxisCode(): void
    {
        // No `mystery` Attribute seeded — must fail fast before any variants exist.
        $client = $this->authenticatedClient();

        $master = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'VAR-MASTER-2',
                'objectTypeId' => $this->objectTypeIdFor(ObjectKind::Product),
            ], JSON_THROW_ON_ERROR),
        ])->toArray();

        $masterId = $master['id'] ?? null;
        \assert(\is_string($masterId));

        $response = $client->request('POST', '/api/products/'.$masterId.'/generate-variants', [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode([
                'axes' => ['mystery' => ['foo']],
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function postGenerateVariantsSkipsExistingSkusOnRerun(): void
    {
        $this->seedAttribute('color', AttributeType::Text);
        $client = $this->authenticatedClient();

        $master = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'VAR-MASTER-3',
                'objectTypeId' => $this->objectTypeIdFor(ObjectKind::Product),
            ], JSON_THROW_ON_ERROR),
        ])->toArray();

        $masterId = $master['id'] ?? null;
        \assert(\is_string($masterId));

        $payload = json_encode([
            'axes' => ['color' => ['red']],
            'sku_template' => '{master_sku}-{color}',
        ], JSON_THROW_ON_ERROR);

        $first = $client->request('POST', '/api/products/'.$masterId.'/generate-variants', [
            'headers' => ['content-type' => 'application/json'],
            'body' => $payload,
        ])->toArray();
        self::assertSame(1, $first['created_count'] ?? null);

        $second = $client->request('POST', '/api/products/'.$masterId.'/generate-variants', [
            'headers' => ['content-type' => 'application/json'],
            'body' => $payload,
        ])->toArray();
        self::assertSame(0, $second['created_count'] ?? null);
        self::assertSame(1, $second['skipped_count'] ?? null);
    }

    #[Test]
    public function postGenerateVariantsTransliteratesPolishCharsInSku(): void
    {
        $this->seedAttribute('color', AttributeType::Text);
        $client = $this->authenticatedClient();

        $master = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'VAR-PL-1',
                'objectTypeId' => $this->objectTypeIdFor(ObjectKind::Product),
            ], JSON_THROW_ON_ERROR),
        ])->toArray();

        $masterId = $master['id'] ?? null;
        \assert(\is_string($masterId));

        // Default template (no sku_template) — diacritics in axis values
        // must collapse to ASCII so the SKU stays URL-safe.
        $defaultTemplate = $client->request(
            'POST',
            '/api/products/'.$masterId.'/generate-variants',
            [
                'headers' => ['content-type' => 'application/json'],
                'body' => json_encode([
                    'axes' => ['color' => ['żółty', 'błękitny']],
                ], JSON_THROW_ON_ERROR),
            ],
        )->toArray();

        /** @var array<int, array<string, mixed>> $defaultRows */
        $defaultRows = (array) $defaultTemplate['created'];
        $defaultSkus = array_map(
            static function (array $row): string {
                $sku = $row['sku'] ?? null;

                return \is_string($sku) ? $sku : '';
            },
            $defaultRows,
        );
        self::assertContains('VAR-PL-1-ZOLTY', $defaultSkus);
        self::assertContains('VAR-PL-1-BLEKITNY', $defaultSkus);

        // Custom template — same transliteration applies, case left intact.
        $customTemplate = $client->request(
            'POST',
            '/api/products/'.$masterId.'/generate-variants',
            [
                'headers' => ['content-type' => 'application/json'],
                'body' => json_encode([
                    'axes' => ['color' => ['Łososiowy']],
                    'sku_template' => '{master_sku}-{color}',
                ], JSON_THROW_ON_ERROR),
            ],
        )->toArray();

        /** @var array<int, array<string, mixed>> $customRows */
        $customRows = (array) $customTemplate['created'];
        $customSkus = array_map(
            static function (array $row): string {
                $sku = $row['sku'] ?? null;

                return \is_string($sku) ? $sku : '';
            },
            $customRows,
        );
        self::assertContains('VAR-PL-1-Lososiowy', $customSkus);
    }

    private function seedAttribute(string $code, AttributeType $type): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $context = self::getContainer()->get(TenantContext::class);
        $context->set($tenant);

        $attribute = new Attribute($code, ['en' => ucfirst($code)], $type);
        self::getContainer()->get(AttributeRepositoryInterface::class)->save($attribute);
    }

    private function reloadObject(string $code): \App\Catalog\Domain\Entity\CatalogObject
    {
        $em = $this->em();
        $em->clear();

        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $context = self::getContainer()->get(TenantContext::class);
        $context->set($tenant);

        $object = self::getContainer()->get(CatalogObjectRepositoryInterface::class)
            ->findByCode($code, ObjectKind::Product, $tenant);
        \assert(null !== $object);

        return $object;
    }
}
