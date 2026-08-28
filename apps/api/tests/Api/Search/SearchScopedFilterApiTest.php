<?php

declare(strict_types=1);

namespace App\Tests\Api\Search;

use App\Catalog\Application\Filter\FilterUrlSerializer;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Channel\Domain\Entity\Channel;
use App\Search\Application\BulkCatalogObjectIndexer;
use App\Search\Application\MeilisearchIndexProvisioner;
use App\Search\Infrastructure\MeilisearchClientFactory;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

/**
 * #2673 — value-context scope on the list path: a scoped DSL blob runs
 * through the Postgres prefilter (`ScopedFilterPrefilter`) and reaches
 * Meilisearch as an `id IN [...]` expression, so a condition can match a
 * channel-scoped `object_values` slot invisible to the global-only index.
 */
final class SearchScopedFilterApiTest extends CatalogApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            self::getContainer()->get(MeilisearchClientFactory::class)->create()->health();
        } catch (Throwable) {
            self::markTestSkipped('Meilisearch container not available; covered by Playwright stack.');
        }

        self::getContainer()->get(MeilisearchIndexProvisioner::class)->provision();
    }

    #[Test]
    public function scopedBlobMatchesChannelSlotWithGlobalFallback(): void
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $channel = new Channel('scope-test-shop', 'Scope Test Shop');
        $channel->assignTenant($tenant);
        $this->em()->persist($channel);

        $brand = new Attribute('scope_brand', ['en' => 'Scope Brand'], AttributeType::Text);
        $brand->changeScopable(true);
        self::getContainer()->get(AttributeRepositoryInterface::class)->save($brand);

        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $type);

        // Object A: global brand=GlobalCo, shop-scoped brand=ScopedCo.
        $objectA = new CatalogObject($type, 'SCOPE-A');
        $objectA->updateAttributeIndex(['scope_brand' => ['value' => 'GlobalCo']]);
        self::getContainer()->get(CatalogObjectRepositoryInterface::class)->save($objectA);
        $scoped = new ObjectValue(
            $objectA,
            $brand,
            ['value' => 'ScopedCo'],
            channelId: $channel->getId(),
        );
        $this->em()->persist($scoped);

        // Object B: global brand=ScopedCo (must NOT match a channel-scoped
        // query for ScopedCo? It MUST match — fallback to global applies).
        $objectB = new CatalogObject($type, 'SCOPE-B');
        $objectB->updateAttributeIndex(['scope_brand' => ['value' => 'OtherCo']]);
        self::getContainer()->get(CatalogObjectRepositoryInterface::class)->save($objectB);

        $this->em()->flush();

        $indexer = self::getContainer()->get(BulkCatalogObjectIndexer::class);
        $indexer->reindex(kind: ObjectKind::Product);
        usleep(600_000);

        $serializer = self::getContainer()->get(FilterUrlSerializer::class);
        $client = $this->authenticatedClient();

        // Without scope: the global slot says GlobalCo → no match for ScopedCo.
        $plainBlob = $serializer->toBase64(['attr' => 'scope_brand', 'op' => '=', 'value' => 'ScopedCo']);
        $plain = $client->request('GET', '/api/search/products?q='.urlencode($plainBlob))->toArray();
        self::assertSame(0, $plain['totalHits'] ?? -1, 'global reading must not see the scoped slot');

        // With channel scope: the scoped slot wins → SCOPE-A matches.
        $scopedBlob = $serializer->toBase64([
            'scope' => ['channel' => 'scope-test-shop'],
            'attr' => 'scope_brand',
            'op' => '=',
            'value' => 'ScopedCo',
        ]);
        $body = $client->request('GET', '/api/search/products?q='.urlencode($scopedBlob))->toArray();
        self::assertSame(1, $body['totalHits'] ?? -1, 'channel scope must surface the scoped slot');
        self::assertSame('SCOPE-A', $this->firstHitCode($body));
        self::assertFalse($body['scopeTruncated'] ?? true);

        // Fallback: GlobalCo in channel scope still matches A? No — the
        // scoped slot (ScopedCo) overrides; B has no scoped slot so its
        // global OtherCo is the effective value. GlobalCo matches nothing.
        $fallbackBlob = $serializer->toBase64([
            'scope' => ['channel' => 'scope-test-shop'],
            'attr' => 'scope_brand',
            'op' => '=',
            'value' => 'OtherCo',
        ]);
        $fallback = $client->request('GET', '/api/search/products?q='.urlencode($fallbackBlob))->toArray();
        self::assertSame(1, $fallback['totalHits'] ?? -1, 'objects without a scoped slot fall back to global');
        self::assertSame('SCOPE-B', $this->firstHitCode($fallback));
    }

    /**
     * @param array<mixed> $body
     */
    private function firstHitCode(array $body): ?string
    {
        $hits = $body['hits'] ?? null;
        if (!\is_array($hits) || !isset($hits[0]) || !\is_array($hits[0])) {
            return null;
        }
        $code = $hits[0]['code'] ?? null;

        return \is_string($code) ? $code : null;
    }

    #[Test]
    public function unknownScopeChannelReturns400(): void
    {
        $serializer = self::getContainer()->get(FilterUrlSerializer::class);
        $client = $this->authenticatedClient();

        $blob = $serializer->toBase64([
            'scope' => ['channel' => 'no-such-channel'],
            'attr' => 'scope_brand',
            'op' => 'IS NOT EMPTY',
        ]);
        $client->request('GET', '/api/search/products?q='.urlencode($blob));

        self::assertResponseStatusCodeSame(400);
    }
}
