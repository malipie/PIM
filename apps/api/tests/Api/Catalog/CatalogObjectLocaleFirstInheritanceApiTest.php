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
 * #1319 — value read inheritance is **locale-first** for every ObjectType.
 *
 * Operator contract: an empty `(EN, Allegro)` reading inherits from
 * `(EN, global)` ("EN, every channel"), never from the channel-only row
 * ("this channel, primary locale" = `(PL, Allegro)` since the primary
 * locale is stored as the global row). The locale fallback chain dominates;
 * the channel match is only a tie-breaker within the same locale rank.
 *
 * The overlay ({@see \App\Catalog\Application\ObjectValueLocaleOverlay})
 * already encodes this via `rank = (maxChainLen - chainPos) * 2 + hasChannel`,
 * which keeps any in-chain locale row above the channel-only row. This test
 * pins that behaviour against regression and proves it is ObjectType-agnostic
 * (Product + Category share the same read path).
 */
final class CatalogObjectLocaleFirstInheritanceApiTest extends CatalogApiTestCase
{
    #[Test]
    public function localeFirstWinsOverChannelForProduct(): void
    {
        $this->seedLocaleChannelAttribute();
        $client = $this->authenticatedClient();

        $create = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'json' => [
                'code' => 'LF-PROD-1',
                'objectTypeId' => $this->objectTypeIdFor(ObjectKind::Product),
                'attributes' => ['loc_chan' => 'global-pl'],
            ],
        ]);
        self::assertSame(201, $create->getStatusCode());
        $id = $this->decode($create->getContent())['id'];
        self::assertIsString($id);

        $this->assertLocaleFirst($client, '/api/products/', $id);
    }

    #[Test]
    public function localeFirstWinsOverChannelForCategory(): void
    {
        $this->seedLocaleChannelAttribute();
        $client = $this->authenticatedClient();

        $create = $client->request('POST', '/api/categories', [
            'headers' => ['content-type' => 'application/ld+json'],
            'json' => [
                'code' => 'lf-cat-1',
                'objectTypeId' => $this->objectTypeIdFor(ObjectKind::Category),
                'categoryTargetObjectTypeId' => $this->objectTypeIdFor(ObjectKind::Product),
            ],
        ]);
        self::assertSame(201, $create->getStatusCode());
        $id = $this->decode($create->getContent())['id'];
        self::assertIsString($id);

        // Seed the global reading via a bare PATCH (categories take no
        // attributes on create).
        $seed = $client->request('PATCH', '/api/categories/'.$id, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['attributes' => ['loc_chan' => 'global-pl']],
        ]);
        self::assertSame(200, $seed->getStatusCode());

        $this->assertLocaleFirst($client, '/api/categories/', $id);
    }

    /**
     * Sets `(EN, global)` and a channel-only `(allegro)` row, then asserts a
     * read at `(EN, allegro)` resolves to the EN-global value — locale-first.
     */
    private function assertLocaleFirst(Client $client, string $base, string $id): void
    {
        // (EN, global) — "EN, every channel".
        $en = $client->request('PATCH', $base.$id.'?locale=en', [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['attributes' => ['loc_chan' => 'english-all-channels']],
        ]);
        self::assertSame(200, $en->getStatusCode());

        // Channel-only (allegro) — "this channel, primary locale" === (PL, Allegro).
        $allegro = $client->request('PATCH', $base.$id.'?channel=allegro', [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['attributes' => ['loc_chan' => 'polish-allegro']],
        ]);
        self::assertSame(200, $allegro->getStatusCode());

        // The contested read: empty (EN, Allegro) must inherit (EN, global),
        // NOT the channel-only (PL, Allegro) row.
        $contested = $this->attr($client, $base, $id, 'en', 'allegro');
        self::assertSame(
            'english-all-channels',
            $contested,
            'Empty (EN, Allegro) must inherit locale-first from (EN, global), not from channel-only (PL, Allegro).',
        );

        // Sanity: each single axis still resolves to its own override.
        self::assertSame('english-all-channels', $this->attr($client, $base, $id, 'en', null));
        self::assertSame('polish-allegro', $this->attr($client, $base, $id, null, 'allegro'));
        self::assertSame('global-pl', $this->attr($client, $base, $id, null, null), 'Bare read = global.');
    }

    private function attr(Client $client, string $base, string $id, ?string $locale, ?string $channel): string
    {
        $params = [];
        if (null !== $locale) {
            $params[] = 'locale='.$locale;
        }
        if (null !== $channel) {
            $params[] = 'channel='.$channel;
        }
        $url = $base.$id.([] !== $params ? '?'.implode('&', $params) : '');

        $response = $client->request('GET', $url);
        self::assertSame(200, $response->getStatusCode());
        $body = $this->decode($response->getContent());
        self::assertIsArray($body['attributesIndexed'] ?? null);
        /** @var array<string, array<string, mixed>> $indexed */
        $indexed = $body['attributesIndexed'];
        $value = $indexed['loc_chan']['value'] ?? null;
        self::assertIsString($value);

        return $value;
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

    /**
     * `loc_chan` is both localizable and scopable, so it can carry a distinct
     * value per locale AND per channel — attached to Product and Category to
     * prove the overlay is ObjectType-agnostic. Seeds the `allegro` channel.
     */
    private function seedLocaleChannelAttribute(): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $repo = self::getContainer()->get(ObjectTypeRepositoryInterface::class);
        $product = $repo->findBuiltInByKind(ObjectKind::Product, $tenant);
        $category = $repo->findBuiltInByKind(ObjectKind::Category, $tenant);
        \assert($product instanceof ObjectType);
        \assert($category instanceof ObjectType);

        $loc = new Attribute('loc_chan', ['en' => 'Loc/Chan', 'pl' => 'Loc/Chan'], AttributeType::Text);
        $loc->changeLocalizable(true);
        $loc->changeScopable(true);
        $em->persist($loc);
        $em->persist(new ObjectTypeAttribute($product, $loc, false, 1));
        $em->persist(new ObjectTypeAttribute($category, $loc, false, 1));

        $em->persist(new Channel('allegro', 'Allegro'));
        $em->flush();
    }
}
