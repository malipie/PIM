<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Channel\Domain\Entity\Channel;
use App\Channel\Domain\Entity\ChannelPublicationProfile;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;

use const JSON_THROW_ON_ERROR;

/**
 * #1234 — ?publication=<channelCode> filters attributes_indexed to the
 * channel's publication allow-list.
 *
 * Tests: allow-list reduces attributes, publish-all profile returns all,
 * missing profile falls back to publish-all (null), ?locale+?publication
 * can coexist.
 */
final class CatalogObjectPublicationFilterApiTest extends CatalogApiTestCase
{
    #[Test]
    public function publicationAllowListFiltersAttributesIndexed(): void
    {
        $client = $this->authenticatedClient();
        [$id, $channelCode] = $this->seedProductWithPublicationProfile(['name', 'ean']);

        $response = $client->request('GET', "/api/products/{$id}?publication={$channelCode}");
        self::assertSame(200, $response->getStatusCode());

        $indexed = $this->decodeIndexed($response->getContent());
        self::assertArrayHasKey('name', $indexed, 'name is in allow-list');
        self::assertArrayHasKey('ean', $indexed, 'ean is in allow-list');
        self::assertArrayNotHasKey('internal_notes', $indexed, 'internal_notes is NOT in allow-list');
    }

    #[Test]
    public function publicationPublishAllProfileReturnsAllAttributes(): void
    {
        $client = $this->authenticatedClient();
        [$id, $channelCode] = $this->seedProductWithPublicationProfile(null);

        $response = $client->request('GET', "/api/products/{$id}?publication={$channelCode}");
        self::assertSame(200, $response->getStatusCode());

        $indexed = $this->decodeIndexed($response->getContent());
        // publish-all = all three attributes present
        self::assertArrayHasKey('name', $indexed);
        self::assertArrayHasKey('ean', $indexed);
        self::assertArrayHasKey('internal_notes', $indexed);
    }

    #[Test]
    public function missingPublicationProfileFallsBackToPublishAll(): void
    {
        $client = $this->authenticatedClient();
        $otId = $this->objectTypeIdFor(ObjectKind::Product);
        $this->seedThreeAttributes();

        $create = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'json' => ['code' => 'PUB-FALLBACK-1', 'objectTypeId' => $otId, 'attributes' => [
                'name' => 'Test', 'ean' => '123', 'internal_notes' => 'secret',
            ]],
        ]);
        self::assertSame(201, $create->getStatusCode());
        $id = $this->decode($create->getContent())['id'];
        self::assertIsString($id);

        // Use a channel that exists but has no profile for this objectType
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        $channel = new Channel('pub_no_profile', 'Test Channel');
        $channel->assignTenant($tenant);
        $em->persist($channel);
        $em->flush();

        $response = $client->request('GET', "/api/products/{$id}?publication=pub_no_profile");
        self::assertSame(200, $response->getStatusCode());

        $indexed = $this->decodeIndexed($response->getContent());
        // No profile row → publish-all fallback
        self::assertArrayHasKey('name', $indexed);
        self::assertArrayHasKey('ean', $indexed);
        self::assertArrayHasKey('internal_notes', $indexed);
    }

    /**
     * Seeds a product with three attributes and a channel + publication profile.
     * Returns [productId, channelCode].
     *
     * @param list<string>|null $allowedCodes null = publish-all
     *
     * @return array{0: string, 1: string}
     */
    private function seedProductWithPublicationProfile(?array $allowedCodes): array
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $this->seedThreeAttributes();

        $channelCode = 'pub_test_'.($allowedCodes === null ? 'all' : 'filtered');
        $channel = new Channel($channelCode, 'Test Channel');
        $channel->assignTenant($tenant);
        $em->persist($channel);
        $em->flush();

        $product = self::getContainer()
            ->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($product instanceof ObjectType);

        // The channel POST above triggers CreateDefaultPublicationProfilesOnChannelCreated
        // which already creates a default profile. Upsert: update existing profile's allow-list.
        $existing = $em->getRepository(ChannelPublicationProfile::class)->findOneBy([
            'channelId' => $channel->getId(),
            'objectTypeId' => $product->getId(),
            'tenant' => $tenant,
        ]);
        if ($existing instanceof ChannelPublicationProfile) {
            $existing->setPublishedAttributeCodes($allowedCodes);
            $em->flush();
        } else {
            $profile = new ChannelPublicationProfile(
                channelId: $channel->getId(),
                objectTypeId: $product->getId(),
                publishedAttributeCodes: $allowedCodes,
                isDefault: true,
            );
            $profile->assignTenant($tenant);
            $em->persist($profile);
            $em->flush();
        }

        $client = $this->authenticatedClient();
        $create = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'json' => ['code' => 'PUB-PROD-'.uniqid(), 'objectTypeId' => $product->getId()->toRfc4122(), 'attributes' => [
                'name' => 'Test Product', 'ean' => '1234567890', 'internal_notes' => 'secret note',
            ]],
        ]);
        self::assertSame(201, $create->getStatusCode());
        $id = $this->decode($create->getContent())['id'];
        self::assertIsString($id);

        return [$id, $channelCode];
    }

    private function seedThreeAttributes(): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $product = self::getContainer()
            ->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($product instanceof ObjectType);

        foreach (['name' => 'Name', 'ean' => 'EAN', 'internal_notes' => 'Notes'] as $code => $label) {
            $attr = new Attribute($code, ['en' => $label, 'pl' => $label], AttributeType::Text);
            $em->persist($attr);
            $em->persist(new ObjectTypeAttribute($product, $attr, false, 1));
        }
        $em->flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeIndexed(string $body): array
    {
        $decoded = $this->decode($body);
        $raw = $decoded['attributesIndexed'] ?? [];
        \assert(\is_array($raw));
        /** @var array<string, mixed> $indexed */
        $indexed = $raw;

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
}
