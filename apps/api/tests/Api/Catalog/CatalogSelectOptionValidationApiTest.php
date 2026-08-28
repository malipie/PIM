<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeOption;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;

/**
 * #1261 — select/multiselect option_code must exist in the live
 * `attribute_options` set; writing an unknown option is rejected with 422.
 */
final class CatalogSelectOptionValidationApiTest extends CatalogApiTestCase
{
    #[Test]
    public function unknownSelectOptionIsRejected(): void
    {
        $this->seedSelectWithOptions('spec_color', ['red', 'blue']);
        $client = $this->authenticatedClient();
        $otId = $this->objectTypeIdFor(ObjectKind::Product);

        $ok = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'json' => ['code' => 'SEL-OK', 'objectTypeId' => $otId, 'attributes' => [
                'spec_color' => ['option_code' => 'red'],
            ]],
        ]);
        self::assertSame(201, $ok->getStatusCode());

        $bad = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'json' => ['code' => 'SEL-BAD', 'objectTypeId' => $otId, 'attributes' => [
                'spec_color' => ['option_code' => 'magenta'],
            ]],
        ]);
        self::assertSame(422, $bad->getStatusCode());
    }

    #[Test]
    public function unknownMultiselectOptionIsRejected(): void
    {
        $this->seedSelectWithOptions('spec_tags', ['new', 'sale'], AttributeType::Multiselect);
        $client = $this->authenticatedClient();
        $otId = $this->objectTypeIdFor(ObjectKind::Product);

        $ok = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'json' => ['code' => 'MUL-OK', 'objectTypeId' => $otId, 'attributes' => [
                'spec_tags' => ['option_codes' => ['new', 'sale']],
            ]],
        ]);
        self::assertSame(201, $ok->getStatusCode());

        $bad = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'json' => ['code' => 'MUL-BAD', 'objectTypeId' => $otId, 'attributes' => [
                'spec_tags' => ['option_codes' => ['new', 'ghost']],
            ]],
        ]);
        self::assertSame(422, $bad->getStatusCode());
    }

    #[Test]
    public function clearingSelectValueIsAllowed(): void
    {
        $this->seedSelectWithOptions('spec_clear', ['a', 'b']);
        $client = $this->authenticatedClient();
        $otId = $this->objectTypeIdFor(ObjectKind::Product);

        // Empty option_code = clearing → no validation, 201.
        $response = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'json' => ['code' => 'SEL-CLEAR', 'objectTypeId' => $otId, 'attributes' => [
                'spec_clear' => ['option_code' => ''],
            ]],
        ]);
        self::assertSame(201, $response->getStatusCode());
    }

    /**
     * @param list<string> $optionCodes
     */
    private function seedSelectWithOptions(
        string $code,
        array $optionCodes,
        AttributeType $type = AttributeType::Select,
    ): void {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $product = self::getContainer()
            ->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($product instanceof ObjectType);

        $attribute = new Attribute($code, ['pl' => $code, 'en' => $code], $type);
        $em->persist($attribute);
        $position = 0;
        foreach ($optionCodes as $optionCode) {
            $em->persist(new AttributeOption(
                attribute: $attribute,
                code: $optionCode,
                label: ['pl' => ucfirst($optionCode), 'en' => ucfirst($optionCode)],
                position: $position++,
            ));
        }
        $em->persist(new ObjectTypeAttribute($product, $attribute, false, 1));
        $em->flush();
    }
}
