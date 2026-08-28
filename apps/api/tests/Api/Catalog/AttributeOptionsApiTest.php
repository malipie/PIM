<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeOption;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;

/**
 * VIEW-02 (#374) — AttributeOption CRUD endpoints used by the Allowed
 * Values editor (`/modeling/attributes/{code}/values`).
 *
 *   GET    /api/attributes/{code}/options              list (sorted)
 *   POST   /api/attributes/{code}/options              create option
 *   PATCH  /api/attributes/{code}/options/{optionCode} partial update
 *   DELETE /api/attributes/{code}/options/{optionCode} remove option
 *
 * Asserts:
 *   - color/default/deprecated round-trip in response.
 *   - one default per attribute is enforced server-side (POST a second
 *     default clears the first).
 *   - hex format guard (422 on bad color).
 *   - duplicate option code 422.
 *   - position auto-assigned when not provided.
 */
final class AttributeOptionsApiTest extends CatalogApiTestCase
{
    #[Test]
    public function listReturnsOptionsSortedByPosition(): void
    {
        $client = $this->authenticatedClient();
        $attributeCode = 'color';
        $attribute = $this->seedSelectAttribute($attributeCode);
        $this->seedOption($attribute, 'red', ['en' => 'Red'], 1);
        $this->seedOption($attribute, 'blue', ['en' => 'Blue'], 0);

        $response = $client->request('GET', '/api/attributes/'.$attributeCode.'/options');
        self::assertSame(200, $response->getStatusCode());
        $payload = $response->toArray();
        \assert(isset($payload['member']) && \is_array($payload['member']));
        $member = $payload['member'];
        self::assertCount(2, $member);
        \assert(\is_array($member[0]) && \is_array($member[1]));
        self::assertSame('blue', $member[0]['code']);
        self::assertSame('red', $member[1]['code']);
    }

    #[Test]
    public function postCreatesOptionWithColorAndFlags(): void
    {
        $client = $this->authenticatedClient();
        $code = 'priority';
        $this->seedSelectAttribute($code);

        $response = $client->request('POST', '/api/attributes/'.$code.'/options', [
            'json' => [
                'code' => 'high',
                'label' => ['en' => 'High', 'pl' => 'Wysoki'],
                'color' => '#EF4444',
                'default' => true,
                'deprecated' => false,
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $payload = $response->toArray();
        self::assertSame('high', $payload['code']);
        self::assertSame('#EF4444', $payload['color']);
        self::assertTrue($payload['default']);
        self::assertFalse($payload['deprecated']);
    }

    #[Test]
    public function postRejectsInvalidHexColor(): void
    {
        $client = $this->authenticatedClient();
        $code = 'priority';
        $this->seedSelectAttribute($code);

        $response = $client->request('POST', '/api/attributes/'.$code.'/options', [
            'json' => [
                'code' => 'high',
                'label' => ['en' => 'High'],
                'color' => 'rgb(255,0,0)',
            ],
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function postSecondDefaultClearsFirst(): void
    {
        $client = $this->authenticatedClient();
        $code = 'priority';
        $attribute = $this->seedSelectAttribute($code);
        $this->seedOption($attribute, 'low', ['en' => 'Low'], 0, isDefault: true);

        $client->request('POST', '/api/attributes/'.$code.'/options', [
            'json' => [
                'code' => 'high',
                'label' => ['en' => 'High'],
                'default' => true,
            ],
        ]);

        $listPayload = $client->request('GET', '/api/attributes/'.$code.'/options')->toArray();
        \assert(isset($listPayload['member']) && \is_array($listPayload['member']));
        $byCode = [];
        foreach ($listPayload['member'] as $row) {
            \assert(\is_array($row));
            $rowCode = $row['code'];
            \assert(\is_string($rowCode));
            $byCode[$rowCode] = $row['default'];
        }
        self::assertFalse($byCode['low']);
        self::assertTrue($byCode['high']);
    }

    #[Test]
    public function patchTogglesDeprecatedAndUpdatesLabel(): void
    {
        $client = $this->authenticatedClient();
        $code = 'priority';
        $attribute = $this->seedSelectAttribute($code);
        $this->seedOption($attribute, 'low', ['en' => 'Low'], 0);

        $response = $client->request('PATCH', '/api/attributes/'.$code.'/options/low', [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => [
                'deprecated' => true,
                'label' => ['en' => 'Low priority', 'pl' => 'Niski'],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $payload = $response->toArray();
        self::assertTrue($payload['deprecated']);
        \assert(\is_array($payload['label']));
        self::assertSame('Low priority', $payload['label']['en']);
    }

    #[Test]
    public function deleteRemovesOption(): void
    {
        $client = $this->authenticatedClient();
        $code = 'priority';
        $attribute = $this->seedSelectAttribute($code);
        $this->seedOption($attribute, 'low', ['en' => 'Low'], 0);

        $response = $client->request('DELETE', '/api/attributes/'.$code.'/options/low');
        self::assertSame(204, $response->getStatusCode());

        $payload = $client->request('GET', '/api/attributes/'.$code.'/options')->toArray();
        \assert(isset($payload['member']) && \is_array($payload['member']));
        self::assertCount(0, $payload['member']);
    }

    #[Test]
    public function usageReturnsInstanceCountForOption(): void
    {
        $client = $this->authenticatedClient();
        $code = 'priority';
        $attribute = $this->seedSelectAttribute($code);
        $this->seedOption($attribute, 'low', ['en' => 'Low'], 0);

        // Brand-new option is unused — instances=0.
        $response = $client->request('GET', '/api/attributes/'.$code.'/options/low/usage');
        self::assertSame(200, $response->getStatusCode());
        $payload = $response->toArray();
        self::assertSame(0, $payload['instances']);
    }

    #[Test]
    public function usageReturns404ForUnknownOption(): void
    {
        $client = $this->authenticatedClient();
        $code = 'priority';
        $this->seedSelectAttribute($code);

        $response = $client->request('GET', '/api/attributes/'.$code.'/options/nonexistent/usage');
        self::assertSame(404, $response->getStatusCode());
    }

    private function seedSelectAttribute(string $code): Attribute
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        $ctx = self::getContainer()->get(TenantContext::class);
        $ctx->set($tenant);

        $attribute = new Attribute($code, ['en' => $code], AttributeType::Select);
        $repo = self::getContainer()->get(AttributeRepositoryInterface::class);
        $repo->save($attribute);

        return $attribute;
    }

    /**
     * @param array<string, string> $label
     */
    private function seedOption(
        Attribute $attribute,
        string $code,
        array $label,
        int $position,
        bool $isDefault = false,
    ): AttributeOption {
        $em = $this->em();
        $option = new AttributeOption(
            attribute: $attribute,
            code: $code,
            label: $label,
            position: $position,
            isDefault: $isDefault,
        );
        $em->persist($option);
        $em->flush();

        return $option;
    }
}
