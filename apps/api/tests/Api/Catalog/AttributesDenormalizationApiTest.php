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
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;

/**
 * Coverage for #45 (0.4.5) — ObjectDenormalizer/Normalizer pipeline.
 *
 * Asserts that POST `/api/products` with a flat `attributes` payload
 * persists `ObjectValue` rows (canonical store), reflects them in the
 * denormalised `attributesIndexed` cache (read shape), and stamps the
 * provenance correctly. PATCH semantics: any code present in the
 * payload is upserted; codes absent stay untouched.
 */
final class AttributesDenormalizationApiTest extends CatalogApiTestCase
{
    #[Test]
    public function postProductWithAttributesCreatesObjectValuesWithManualProvenance(): void
    {
        $colorId = $this->seedAttribute('color', AttributeType::Text);
        unset($colorId);

        $client = $this->authenticatedClient();
        $response = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'ATTR-001',
                'objectTypeId' => $this->objectTypeIdFor(ObjectKind::Product),
                'attributes' => ['color' => 'red'],
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(201);
        $body = $response->toArray();

        // Read shape comes from the denormalised cache (rebuilt by
        // AttributesIndexedSyncListener post-flush).
        $cache = $body['attributesIndexed'] ?? [];
        \assert(\is_array($cache));
        // Cache stores the canonical JSONB shape `{value: 'red'}` per-attribute;
        // a future #45-followup may unwrap scalar wrappers in the normalizer
        // so the API surface is `{color: 'red'}` directly.
        self::assertSame(['value' => 'red', 'provenance' => 'manual'], $cache['color'] ?? null);

        // Canonical store: one ObjectValue with provenance Manual.
        $object = $this->reloadObject('ATTR-001');
        $values = self::getContainer()->get(ObjectValueRepositoryInterface::class)
            ->findByObject($object);

        self::assertCount(1, $values);
        self::assertSame(Provenance::Manual, $values[0]->getProvenance());
        self::assertSame(['value' => 'red'], $values[0]->getValue());
    }

    #[Test]
    public function patchUpdatesAttributeAndKeepsExistingValuesUntouched(): void
    {
        $this->seedAttribute('color', AttributeType::Text);
        $this->seedAttribute('weight', AttributeType::Number);

        $client = $this->authenticatedClient();
        $created = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'ATTR-PATCH',
                'objectTypeId' => $this->objectTypeIdFor(ObjectKind::Product),
                'attributes' => ['color' => 'red', 'weight' => 12.5],
            ], JSON_THROW_ON_ERROR),
        ])->toArray();

        $id = $created['id'] ?? null;
        \assert(\is_string($id));

        $response = $client->request('PATCH', '/api/products/'.$id, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'body' => json_encode([
                'attributes' => ['color' => 'blue'],
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        $cache = $body['attributesIndexed'] ?? [];
        \assert(\is_array($cache));

        // `color` updated, `weight` left alone (Patch semantics). Cache
        // mirrors the canonical JSONB wrapper shape from ObjectValue.
        self::assertSame(['value' => 'blue', 'provenance' => 'manual'], $cache['color'] ?? null);
        self::assertSame(['value' => 12.5, 'provenance' => 'manual'], $cache['weight'] ?? null);
    }

    #[Test]
    public function unknownAttributeCodeIsSilentlyDropped(): void
    {
        // Only `color` exists; `mystery` payload key has no Attribute.
        $this->seedAttribute('color', AttributeType::Text);

        $client = $this->authenticatedClient();
        $response = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'ATTR-DROP',
                'objectTypeId' => $this->objectTypeIdFor(ObjectKind::Product),
                'attributes' => ['color' => 'green', 'mystery' => 'whatever'],
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(201);
        $object = $this->reloadObject('ATTR-DROP');
        $values = self::getContainer()->get(ObjectValueRepositoryInterface::class)
            ->findByObject($object);

        // Only `color` lands; `mystery` dropped.
        self::assertCount(1, $values);
        self::assertSame('color', $values[0]->getAttribute()->getCode());
    }

    private function seedAttribute(string $code, AttributeType $type): Uuid
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $context = self::getContainer()->get(TenantContext::class);
        $context->set($tenant);

        $attribute = new Attribute($code, ['en' => ucfirst($code)], $type);
        self::getContainer()->get(AttributeRepositoryInterface::class)->save($attribute);

        return $attribute->getId();
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
