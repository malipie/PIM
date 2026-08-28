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
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;

use const JSON_THROW_ON_ERROR;

/**
 * #1148 — locale-scoped read (`?locale=`) + write (`PATCH ?locale=`).
 *
 * Contract: a localizable attribute carries a distinct value per locale;
 * the primary locale === the global row (no migration for legacy data);
 * a non-localizable attribute stays shared regardless of `?locale=`.
 */
final class CatalogObjectLocaleValuesApiTest extends CatalogApiTestCase
{
    #[Test]
    public function localeScopedReadAndWriteRoundTrips(): void
    {
        $this->seedLocaleAttributes();
        $client = $this->authenticatedClient();
        $otId = $this->objectTypeIdFor(ObjectKind::Product);

        // Create on the primary locale (global): loc_title = "Tytuł PL".
        $create = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'json' => [
                'code' => 'LOC-RT-1',
                'objectTypeId' => $otId,
                'attributes' => ['loc_title' => 'Tytuł PL', 'shared_color' => 'red'],
            ],
        ]);
        self::assertSame(201, $create->getStatusCode());
        $id = $this->decode($create->getContent())['id'];
        self::assertIsString($id);

        // PATCH under EN: localizable loc_title → EN row; non-localizable
        // shared_color → global (overwrites red).
        $patch = $client->request('PATCH', '/api/products/'.$id.'?locale=en', [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['attributes' => ['loc_title' => 'Title EN', 'shared_color' => 'blue']],
        ]);
        self::assertSame(200, $patch->getStatusCode());

        // Primary locale (pl) === global → keeps "Tytuł PL".
        $pl = $this->attrs($client, $id, 'pl');
        self::assertSame('Tytuł PL', $pl['loc_title']['value']);
        self::assertSame('blue', $pl['shared_color']['value'], 'Non-localizable write is global.');

        // EN → overlay swaps loc_title; shared_color falls back to global.
        $en = $this->attrs($client, $id, 'en');
        self::assertSame('Title EN', $en['loc_title']['value']);
        self::assertSame('blue', $en['shared_color']['value'], 'Non-localizable is shared across locales.');

        // Bare GET (no ?locale=) matches the primary locale reading.
        $bare = $this->attrs($client, $id, null);
        self::assertSame('Tytuł PL', $bare['loc_title']['value']);

        // Poly-kind path serves the same overlay.
        $poly = $this->attrs($client, $id, 'en', '/api/objects/');
        self::assertSame('Title EN', $poly['loc_title']['value']);
    }

    #[Test]
    public function unknownLocaleIsRejected(): void
    {
        $this->seedLocaleAttributes();
        $client = $this->authenticatedClient();
        $otId = $this->objectTypeIdFor(ObjectKind::Product);

        $create = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'json' => ['code' => 'LOC-RT-2', 'objectTypeId' => $otId, 'attributes' => ['loc_title' => 'X']],
        ]);
        self::assertSame(201, $create->getStatusCode());
        $id = $this->decode($create->getContent())['id'];
        self::assertIsString($id);

        $read = $client->request('GET', '/api/products/'.$id.'?locale=zz');
        self::assertSame(422, $read->getStatusCode());

        $write = $client->request('PATCH', '/api/products/'.$id.'?locale=zz', [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['attributes' => ['loc_title' => 'Y']],
        ]);
        self::assertSame(422, $write->getStatusCode());
    }

    /**
     * #1230 — collection overlay provider must accept ?locale=pl (the tenant's
     * primary/enabled locale) without 4xx. Uses 'pl' because the test tenant's
     * enabledLocales default includes 'pl'; 'en' would also pass (default also
     * includes it) but 'pl' is the primary and guaranteed available.
     */
    #[Test]
    public function collectionWithEnabledLocaleParamYields200(): void
    {
        $client = $this->authenticatedClient();
        $otId = $this->objectTypeIdFor(ObjectKind::Product);
        $create = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'json' => ['code' => 'LOC-COL-1', 'objectTypeId' => $otId],
        ]);
        self::assertSame(201, $create->getStatusCode());

        // ?locale=pl: primary locale, always enabled, overlay no-ops (effectiveLocale=null).
        self::assertSame(200, $client->request('GET', '/api/products?locale=pl')->getStatusCode());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function attrs(Client $client, string $id, ?string $locale, string $base = '/api/products/'): array
    {
        $url = $base.$id.(null !== $locale ? '?locale='.$locale : '');
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

    private function seedLocaleAttributes(): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $product = self::getContainer()
            ->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($product instanceof ObjectType);

        $title = new Attribute('loc_title', ['en' => 'Title', 'pl' => 'Tytuł'], AttributeType::Text);
        $title->changeLocalizable(true);
        $color = new Attribute('shared_color', ['en' => 'Color', 'pl' => 'Kolor'], AttributeType::Text);
        // shared_color stays non-localizable (default false).

        $position = 1;
        foreach ([$title, $color] as $attribute) {
            $em->persist($attribute);
            $em->persist(new ObjectTypeAttribute($product, $attribute, false, $position++));
        }
        $em->flush();
    }
}
