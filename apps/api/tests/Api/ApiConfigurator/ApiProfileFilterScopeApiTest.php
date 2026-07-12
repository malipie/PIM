<?php

declare(strict_types=1);

namespace App\Tests\Api\ApiConfigurator;

use App\ApiConfigurator\Application\ApiKeyGenerator;
use App\ApiConfigurator\Domain\Entity\ApiKey;
use App\ApiConfigurator\Domain\Entity\ApiProfile;
use App\ApiConfigurator\Domain\Enum\OutputFormat;
use App\ApiConfigurator\Domain\Repository\ApiKeyRepositoryInterface;
use App\ApiConfigurator\Domain\Repository\ApiProfileRepositoryInterface;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;

/**
 * #2527 — the ApiProfile's `filters` / `objectTypeIds` scope the rows the
 * live API returns to a partner integration (not just which fields are
 * serialised). A profile scoped to `status=published` must never leak a
 * draft, on the collection OR a direct item GET.
 */
final class ApiProfileFilterScopeApiTest extends ApiConfiguratorApiTestCase
{
    #[Test]
    public function collectionReturnsOnlyObjectsMatchingTheProfileStatusFilter(): void
    {
        $rawKey = $this->seedProfileAndKey('published-only', ['status' => 'published']);
        $this->seedProducts();

        $client = static::createClient();
        $body = $client->request('GET', '/api/products', [
            'headers' => ['X-API-Key' => $rawKey],
        ])->toArray();

        self::assertResponseIsSuccessful();
        $codes = $this->memberCodes($body);
        self::assertContains('PUB-1', $codes);
        self::assertContains('PUB-2', $codes);
        self::assertNotContains('DRAFT-1', $codes, 'the draft must not leak through a published-only profile');
        self::assertNotContains('ARCHIVED-1', $codes);
    }

    #[Test]
    public function itemGetOnAnOutOfScopeObjectReturns404(): void
    {
        $rawKey = $this->seedProfileAndKey('published-only-2', ['status' => 'published']);
        [, $draftId] = $this->seedProducts();

        $client = static::createClient();
        $client->request('GET', '/api/products/'.$draftId, [
            'headers' => ['X-API-Key' => $rawKey],
        ]);

        // A draft is outside a published-only profile — same 404 shape as a
        // kind mismatch, so the collection scope cannot be bypassed by id.
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function emptyProfileFilterReturnsEveryObject(): void
    {
        $rawKey = $this->seedProfileAndKey('unfiltered', []);
        $this->seedProducts();

        $client = static::createClient();
        $body = $client->request('GET', '/api/products', [
            'headers' => ['X-API-Key' => $rawKey],
        ])->toArray();

        self::assertResponseIsSuccessful();
        $codes = $this->memberCodes($body);
        self::assertContains('DRAFT-1', $codes, 'a profile with no filters must not narrow the collection');
        self::assertContains('PUB-1', $codes);
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return list<string>
     */
    private function memberCodes(array $body): array
    {
        $members = $body['member'] ?? $body['hydra:member'] ?? [];
        if (!\is_array($members)) {
            return [];
        }

        $codes = [];
        foreach ($members as $row) {
            $code = \is_array($row) ? ($row['code'] ?? null) : null;
            if (\is_string($code)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function seedProfileAndKey(string $code, array $filters): string
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $profile = new ApiProfile($code, ucfirst($code), OutputFormat::JSON_LD);
        $profile->setFilters($filters);
        self::getContainer()->get(ApiProfileRepositoryInterface::class)->save($profile);

        $generated = self::getContainer()->get(ApiKeyGenerator::class)->generate();
        $apiKey = new ApiKey(
            keyHash: $generated->keyHash,
            keyPrefix: $generated->keyPrefix,
            name: 'scope-key',
            scopes: [$code],
        );
        self::getContainer()->get(ApiKeyRepositoryInterface::class)->save($apiKey);

        return $generated->rawKey;
    }

    /**
     * @return array{0: string, 1: string} [publishedId, draftId]
     */
    private function seedProducts(): array
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $productType = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt', 'en' => 'Product']);
        $productType->assignTenant($tenant);
        $em->persist($productType);

        $make = function (string $code, string $status) use ($tenant, $productType): CatalogObject {
            $object = new CatalogObject($productType, $code);
            $object->assignTenant($tenant);
            $object->forceStatus($status);
            $this->em()->persist($object);

            return $object;
        };

        $published = $make('PUB-1', CatalogObject::STATUS_PUBLISHED);
        $make('PUB-2', CatalogObject::STATUS_PUBLISHED);
        $draft = $make('DRAFT-1', CatalogObject::STATUS_DRAFT);
        $make('ARCHIVED-1', CatalogObject::STATUS_ARCHIVED);
        $em->flush();

        return [$published->getId()->toRfc4122(), $draft->getId()->toRfc4122()];
    }
}
