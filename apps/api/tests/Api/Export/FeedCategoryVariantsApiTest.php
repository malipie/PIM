<?php

declare(strict_types=1);

namespace App\Tests\Api\Export;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectCategory;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Channel\Domain\Entity\Channel;
use App\Channel\Domain\Entity\ChannelCategoryNode;
use App\Channel\Domain\Entity\ChannelCategoryNodeMapping;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use DOMDocument;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * XMLF-P3-03 — end-to-end: marketplace category ids from the channel tree
 * (`fmt: category` ← ChannelCategoryNode.external_code through the master
 * category mapping) and flat variants (a master with variants is represented
 * by its variants, each with item_group_id = master SKU).
 */
final class FeedCategoryVariantsApiTest extends CatalogApiTestCase
{
    #[Test]
    public function regenerationResolvesExternalCodesAndExpandsVariantsFlat(): void
    {
        $admin = $this->authenticatedClient();
        $tenant = $this->tenant();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $em = $this->em();

        // Channel tree: node with the marketplace id, mapped from a master category.
        $channel = new Channel('ceneo', 'Ceneo');
        $em->persist($channel);
        $node = new ChannelCategoryNode($channel, 'elektronika', ['pl' => 'Elektronika'], null, '123');
        // The ltree path is materialised by the CHC add handler, not the
        // entity — a root node's path is its own ltree label.
        $node->attachToPath($node->ltreeLabel());
        $em->persist($node);

        $types = self::getContainer()->get(ObjectTypeRepositoryInterface::class);
        $categoryType = $types->findBuiltInByKind(ObjectKind::Category, $tenant);
        $productType = $types->findBuiltInByKind(ObjectKind::Product, $tenant);
        self::assertNotNull($categoryType);
        self::assertNotNull($productType);

        $masterCategory = new CatalogObject($categoryType, 'elektronika');
        $em->persist($masterCategory);
        $em->flush();

        $mapping = new ChannelCategoryNodeMapping($channel, $masterCategory->getId(), [$node->getId()->toRfc4122()]);
        $em->persist($mapping);

        // Catalog: a categorised master with two variants + a categorised
        // simple product + an uncategorised product (the warning path).
        $master = new CatalogObject($productType, 'KL-MASTER');
        $variantA = new CatalogObject($productType, 'KL-MASTER-S');
        $variantA->assignParent($master);
        $variantB = new CatalogObject($productType, 'KL-MASTER-M');
        $variantB->assignParent($master);
        $simple = new CatalogObject($productType, 'KL-SIMPLE');
        $orphan = new CatalogObject($productType, 'KL-NOCAT');
        foreach ([$master, $variantA, $variantB, $simple, $orphan] as $object) {
            $em->persist($object);
        }
        $em->persist(new ObjectCategory($master, $masterCategory, isPrimary: true));
        $em->persist(new ObjectCategory($simple, $masterCategory, isPrimary: true));
        $em->flush();

        $feedId = $this->createFeed($admin, $channel->getId());
        self::assertSame(202, $admin->request('POST', '/api/feeds/'.$feedId.'/regenerate')->getStatusCode());

        $url = $this->mintPullUrl($admin, $feedId);
        $anon = static::createClient();
        $response = $anon->request('GET', $url);
        self::assertSame(200, $response->getStatusCode());
        $xml = $response->getContent(false);

        $dom = new DOMDocument();
        self::assertNotFalse($dom->loadXML($xml), 'feed must stay well-formed');

        $items = [];
        foreach ($dom->getElementsByTagName('product') as $item) {
            $sku = $item->getElementsByTagName('sku')->item(0)?->textContent;
            $items[$sku] = [
                'cat' => $item->getElementsByTagName('cat')->item(0)?->textContent,
                'group' => $item->getElementsByTagName('item_group_id')->item(0)?->textContent,
            ];
        }

        // Flat variants: the master is REPRESENTED by its two variants; the
        // simple product stays a single item without a group id.
        self::assertArrayNotHasKey('KL-MASTER', $items, 'a master with variants is not its own item');
        self::assertSame('KL-MASTER', $items['KL-MASTER-S']['group'] ?? null);
        self::assertSame('KL-MASTER', $items['KL-MASTER-M']['group'] ?? null);
        self::assertArrayHasKey('KL-SIMPLE', $items);
        self::assertNull($items['KL-SIMPLE']['group'], 'standalone product carries no item_group_id');

        // Channel category resolution: variants inherit nothing automatically —
        // only categorised objects resolve. The simple product resolves '123';
        // the uncategorised one was skipped (required cat), so it is absent.
        self::assertSame('123', $items['KL-SIMPLE']['cat']);
        self::assertArrayNotHasKey('KL-NOCAT', $items, 'required category unresolved → skipped');

        // The health trail explains the skip.
        $runsBody = $admin->request('GET', '/api/feeds/'.$feedId.'/runs?limit=1')->toArray(false);
        $items2 = $runsBody['items'];
        self::assertIsArray($items2);
        $lastRun = $items2[0];
        self::assertIsArray($lastRun);
        self::assertGreaterThanOrEqual(1, $lastRun['skipped_count']);
    }

    private function tenant(): Tenant
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }

    private function createFeed(Client $client, Uuid $channelId): string
    {
        $created = $client->request('POST', '/api/feeds', ['json' => [
            'template_kind' => 'custom',
            'code' => 'ceneo_like',
            'name' => 'Ceneo-like feed',
            'object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
            'channel_id' => $channelId->toRfc4122(),
            'descriptor' => [
                'root' => ['element' => 'products'],
                'item' => [
                    'element' => 'product',
                    'slots' => [
                        ['slot' => 'sku', 'node' => 'element', 'required' => true, 'fmt' => 'text'],
                        ['slot' => 'cat', 'node' => 'element', 'required' => true, 'fmt' => 'category'],
                        ['slot' => 'item_group_id', 'node' => 'element', 'fmt' => 'text'],
                    ],
                ],
            ],
            'field_mappings' => [
                ['slot' => 'sku', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
                ['slot' => 'item_group_id', 'source' => ['kind' => 'attribute', 'ref' => 'parent_sku']],
            ],
            'locale' => 'pl',
        ]]);
        self::assertSame(201, $created->getStatusCode());

        $id = $created->toArray(false)['id'];
        self::assertIsString($id);

        return $id;
    }

    private function mintPullUrl(Client $client, string $feedId): string
    {
        $minted = $client->request('POST', '/api/feeds/'.$feedId.'/token');
        self::assertSame(201, $minted->getStatusCode());

        $url = $minted->toArray(false)['url'];
        self::assertIsString($url);

        return $url;
    }
}
