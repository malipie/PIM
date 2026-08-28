<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Channel\Domain\Entity\Channel;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;

use const JSON_THROW_ON_ERROR;

/**
 * #1154 — channel-scoped read (`?channel=`) + write (`PATCH ?channel=`).
 *
 * Mirrors the locale axis (#1148): a scopable attribute carries a distinct
 * value per channel with fallback to the global row; a non-scopable
 * attribute stays shared regardless of `?channel=`.
 */
final class CatalogObjectChannelValuesApiTest extends CatalogApiTestCase
{
    #[Test]
    public function channelScopedReadAndWriteRoundTrips(): void
    {
        $this->seedChannelAttributes();
        $client = $this->authenticatedClient();
        $otId = $this->objectTypeIdFor(ObjectKind::Product);

        $create = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'json' => [
                'code' => 'CHAN-RT-1',
                'objectTypeId' => $otId,
                'attributes' => ['chan_color' => 'red', 'plain_brand' => 'Acme'],
            ],
        ]);
        self::assertSame(201, $create->getStatusCode());
        $id = $this->decode($create->getContent())['id'];
        self::assertIsString($id);

        // PATCH under the "shopify" channel: scopable chan_color → channel
        // row; non-scopable plain_brand → global (overwrites Acme).
        $patch = $client->request('PATCH', '/api/products/'.$id.'?channel=shopify', [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['attributes' => ['chan_color' => 'blue', 'plain_brand' => 'AcmeX']],
        ]);
        self::assertSame(200, $patch->getStatusCode());

        // Global (no channel) → chan_color keeps red; brand is global AcmeX.
        $global = $this->attrs($client, $id, null);
        self::assertSame('red', $global['chan_color']['value']);
        self::assertSame('AcmeX', $global['plain_brand']['value'], 'Non-scopable write is global.');

        // shopify → overlay swaps chan_color; brand shared.
        $shopify = $this->attrs($client, $id, 'shopify');
        self::assertSame('blue', $shopify['chan_color']['value']);
        self::assertSame('AcmeX', $shopify['plain_brand']['value'], 'Non-scopable is shared across channels.');

        // Another channel with no override → falls back to global.
        $baselinker = $this->attrs($client, $id, 'baselinker');
        self::assertSame('red', $baselinker['chan_color']['value']);

        // Poly-kind path serves the same overlay.
        $poly = $this->attrs($client, $id, 'shopify', '/api/objects/');
        self::assertSame('blue', $poly['chan_color']['value']);
    }

    #[Test]
    public function unknownChannelIsRejected(): void
    {
        $this->seedChannelAttributes();
        $client = $this->authenticatedClient();
        $otId = $this->objectTypeIdFor(ObjectKind::Product);

        $create = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'json' => ['code' => 'CHAN-RT-2', 'objectTypeId' => $otId, 'attributes' => ['chan_color' => 'x']],
        ]);
        self::assertSame(201, $create->getStatusCode());
        $id = $this->decode($create->getContent())['id'];
        self::assertIsString($id);

        $read = $client->request('GET', '/api/products/'.$id.'?channel=nope');
        self::assertSame(422, $read->getStatusCode());

        $write = $client->request('PATCH', '/api/products/'.$id.'?channel=nope', [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['attributes' => ['chan_color' => 'y']],
        ]);
        self::assertSame(422, $write->getStatusCode());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function attrs(Client $client, string $id, ?string $channel, string $base = '/api/products/'): array
    {
        $url = $base.$id.(null !== $channel ? '?channel='.$channel : '');
        $response = $client->request('GET', $url);
        self::assertSame(200, $response->getStatusCode());
        $body = $this->decode($response->getContent());
        self::assertIsArray($body['attributesIndexed'] ?? null);

        /** @var array<string, array<string, mixed>> $indexed */
        $indexed = $body['attributesIndexed'];

        return $indexed;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function seedChannelAttributes(): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $product = self::getContainer()
            ->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($product instanceof ObjectType);

        $color = new Attribute('chan_color', ['en' => 'Color', 'pl' => 'Kolor'], AttributeType::Text);
        $color->changeScopable(true);
        $brand = new Attribute('plain_brand', ['en' => 'Brand', 'pl' => 'Marka'], AttributeType::Text);
        // plain_brand stays non-scopable (default false).

        $position = 1;
        foreach ([$color, $brand] as $attribute) {
            $em->persist($attribute);
            $em->persist(new ObjectTypeAttribute($product, $attribute, false, $position++));
        }

        $em->persist(new Channel('shopify', 'Shopify'));
        $em->persist(new Channel('baselinker', 'BaseLinker'));
        $em->flush();
    }
}
