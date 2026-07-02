<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Application\BuiltInSystemAttributesSeeder;
use App\Catalog\Application\ObjectTypeService;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\AttributeGroupAttribute;
use App\Catalog\Domain\Entity\AttributeOption;
use App\Catalog\Domain\Entity\ObjectTypeAttributeGroup;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Catalog\Presentation\Controller\ProductReadEndpointsController;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;

use const JSON_THROW_ON_ERROR;

/**
 * VIEW-07.1 (#421+) — `GET /api/products/{id}/effective-attribute-groups`
 * synthetic "default" group coverage. Verifies that ObjectType-attached
 * attributes which are not declared in any AttributeGroup are still
 * surfaced to the form-renderer in a sentinel-id bucket the frontend
 * recognises.
 */
final class EffectiveAttributeGroupsApiTest extends CatalogApiTestCase
{
    #[Test]
    public function exposesLooseObjectTypeAttributesInDefaultGroup(): void
    {
        $client = $this->authenticatedClient();
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $productType = self::getContainer()
            ->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $productType);

        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($tenant);

        // Attach two loose attributes (no AttributeGroup membership) to
        // the built-in product ObjectType.
        $brandAttr = new Attribute('brand_view07', ['pl' => 'Marka', 'en' => 'Brand'], AttributeType::Text);
        $weightAttr = new Attribute('weight_view07', ['pl' => 'Waga', 'en' => 'Weight'], AttributeType::Number);
        $em = $this->em();
        $em->persist($brandAttr);
        $em->persist($weightAttr);
        $em->flush();

        $service = self::getContainer()->get(ObjectTypeService::class);
        $service->assignAttribute($productType, $brandAttr, required: false, sortOrder: 0);
        $service->assignAttribute($productType, $weightAttr, required: false, sortOrder: 1);

        $tenantContext->clear();

        // Create a product so the endpoint has a concrete subject.
        $created = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'V071-LOOSE',
                'objectTypeId' => $productType->getId()->toRfc4122(),
            ], JSON_THROW_ON_ERROR),
        ])->toArray();
        $id = $created['id'] ?? null;
        \assert(\is_string($id));

        $response = $client->request('GET', '/api/products/'.$id.'/effective-attribute-groups');
        self::assertResponseIsSuccessful();
        $body = $response->toArray();

        $groups = $body['groups'] ?? [];
        \assert(\is_array($groups));
        $defaultGroup = null;
        foreach ($groups as $group) {
            \assert(\is_array($group));
            if (ProductReadEndpointsController::SYNTHETIC_DEFAULT_GROUP_ID === ($group['id'] ?? null)) {
                $defaultGroup = $group;
                break;
            }
        }

        self::assertNotNull($defaultGroup, 'Synthetic default group missing from response.');
        self::assertSame('default', $defaultGroup['code'] ?? null);
        self::assertTrue($defaultGroup['is_synthetic'] ?? false);
        self::assertFalse($defaultGroup['is_system_group'] ?? true);

        $defaultAttributes = $defaultGroup['attributes'] ?? [];
        \assert(\is_array($defaultAttributes));
        $codes = array_map(
            static function ($attr): ?string {
                \assert(\is_array($attr));
                $code = $attr['code'] ?? null;

                return \is_string($code) ? $code : null;
            },
            $defaultAttributes,
        );

        self::assertContains('brand_view07', $codes);
        self::assertContains('weight_view07', $codes);
    }

    #[Test]
    public function exposesIsLocalizablePerAttributeAndTenantLocales(): void
    {
        $client = $this->authenticatedClient();
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $productType = self::getContainer()
            ->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $productType);

        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($tenant);

        $localized = new Attribute('loc_title_eag', ['pl' => 'Tytuł', 'en' => 'Title'], AttributeType::Text);
        $localized->changeLocalizable(true);
        $shared = new Attribute('shared_brand_eag', ['pl' => 'Marka', 'en' => 'Brand'], AttributeType::Text);
        $em = $this->em();
        $em->persist($localized);
        $em->persist($shared);
        $em->flush();

        $service = self::getContainer()->get(ObjectTypeService::class);
        $service->assignAttribute($productType, $localized, required: false, sortOrder: 0);
        $service->assignAttribute($productType, $shared, required: false, sortOrder: 1);
        $tenantContext->clear();

        $created = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'EAG-LOC',
                'objectTypeId' => $productType->getId()->toRfc4122(),
            ], JSON_THROW_ON_ERROR),
        ])->toArray();
        $id = $created['id'] ?? null;
        \assert(\is_string($id));

        $body = $client->request('GET', '/api/products/'.$id.'/effective-attribute-groups')->toArray();

        // is_localizable exposed per attribute.
        $groups = $body['groups'] ?? [];
        \assert(\is_array($groups));
        $byCode = [];
        foreach ($groups as $group) {
            \assert(\is_array($group));
            $attributes = $group['attributes'] ?? [];
            \assert(\is_array($attributes));
            foreach ($attributes as $attr) {
                \assert(\is_array($attr) && \is_string($attr['code'] ?? null));
                $byCode[$attr['code']] = $attr;
            }
        }
        self::assertArrayHasKey('loc_title_eag', $byCode);
        self::assertTrue($byCode['loc_title_eag']['is_localizable']);
        self::assertArrayHasKey('shared_brand_eag', $byCode);
        self::assertFalse($byCode['shared_brand_eag']['is_localizable']);

        // Tenant locales surfaced for the picker (default first).
        $locales = $body['locales'] ?? [];
        \assert(\is_array($locales) && [] !== $locales);
        $codes = [];
        foreach ($locales as $entry) {
            \assert(\is_array($entry) && \is_string($entry['code'] ?? null));
            $codes[] = $entry['code'];
        }
        self::assertContains('pl', $codes);
        self::assertContains('en', $codes);
        $first = $locales[0];
        self::assertTrue($first['is_default'], 'Default locale is listed first.');
        self::assertSame('pl', $first['code']);
    }

    #[Test]
    public function exposesDisplayModePerGroupAndSyntheticGroupIsStacked(): void
    {
        // MODR-03 (#925) — every group in the payload carries a
        // `display_mode`. Explicitly attached real groups can be
        // `stacked`; the synthetic "default" bucket is always `stacked`
        // so it never spawns a tab on the frontend.
        $client = $this->authenticatedClient();
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $productType = self::getContainer()
            ->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $productType);

        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($tenant);
        $group = new AttributeGroup('modr03_specs', ['pl' => 'Specyfikacja', 'en' => 'Specs']);
        $em = $this->em();
        $em->persist($group);
        $em->persist(new ObjectTypeAttributeGroup(
            $productType,
            $group,
            position: 0,
            displayMode: ObjectTypeAttributeGroup::DISPLAY_MODE_STACKED,
        ));
        $brandAttr = new Attribute('modr03_brand', ['pl' => 'Marka', 'en' => 'Brand'], AttributeType::Text);
        $em->persist($brandAttr);
        $em->flush();
        self::getContainer()->get(ObjectTypeService::class)
            ->assignAttribute($productType, $brandAttr, required: false, sortOrder: 0);
        $tenantContext->clear();

        $created = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'MODR03-DM',
                'objectTypeId' => $productType->getId()->toRfc4122(),
            ], JSON_THROW_ON_ERROR),
        ])->toArray();
        $id = $created['id'] ?? null;
        \assert(\is_string($id));

        $body = $client->request('GET', '/api/products/'.$id.'/effective-attribute-groups')->toArray();
        $groups = $body['groups'] ?? [];
        \assert(\is_array($groups));

        $modesByCode = [];
        foreach ($groups as $g) {
            \assert(\is_array($g));
            self::assertArrayHasKey('display_mode', $g, 'Group missing display_mode: '.json_encode($g));
            self::assertContains($g['display_mode'], ['tab', 'stacked']);
            $code = $g['code'] ?? null;
            \assert(\is_string($code));
            $modesByCode[$code] = $g['display_mode'];
        }

        self::assertSame('stacked', $modesByCode['modr03_specs'] ?? null, 'explicit group must preserve stacked display mode');
        self::assertSame('stacked', $modesByCode['default'] ?? null, 'synthetic default group must be stacked');
    }

    #[Test]
    public function omitsDefaultGroupWhenEveryAttributeBelongsToAGroup(): void
    {
        $client = $this->authenticatedClient();
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $productType = self::getContainer()
            ->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $productType);

        $created = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'V071-CLEAN',
                'objectTypeId' => $productType->getId()->toRfc4122(),
            ], JSON_THROW_ON_ERROR),
        ])->toArray();
        $id = $created['id'] ?? null;
        \assert(\is_string($id));

        $response = $client->request('GET', '/api/products/'.$id.'/effective-attribute-groups');
        self::assertResponseIsSuccessful();
        $body = $response->toArray();

        $groups = $body['groups'] ?? [];
        \assert(\is_array($groups));
        foreach ($groups as $group) {
            \assert(\is_array($group));
            self::assertNotSame(
                ProductReadEndpointsController::SYNTHETIC_DEFAULT_GROUP_ID,
                $group['id'] ?? null,
                'Synthetic default group should be absent when no loose attributes are attached.',
            );
        }
    }

    #[Test]
    public function looseAttributeAlsoMemberOfRealGroupIsNotDuplicated(): void
    {
        $client = $this->authenticatedClient();
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $productType = self::getContainer()
            ->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $productType);

        // A system attribute can be both a member of an explicitly attached
        // group and a loose ObjectType attribute. The endpoint must surface
        // it exactly once (under the real group, not in the synthetic
        // default bucket).
        self::getContainer()->get(BuiltInSystemAttributesSeeder::class)->seed($tenant);
        $createdAt = self::getContainer()
            ->get(AttributeRepositoryInterface::class)
            ->findByCode('created_at', $tenant);
        \assert(null !== $createdAt);

        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($tenant);
        $timestampsGroup = new AttributeGroup('timestamps_v071', ['pl' => 'Daty', 'en' => 'Timestamps']);
        $em = $this->em();
        $em->persist($timestampsGroup);
        $em->flush();
        $em->persist(new AttributeGroupAttribute($timestampsGroup, $createdAt, 0));
        $em->persist(new ObjectTypeAttributeGroup($productType, $timestampsGroup, position: 0));
        $em->flush();
        $service = self::getContainer()->get(ObjectTypeService::class);
        $service->assignAttribute($productType, $createdAt, required: false, sortOrder: 99);
        $tenantContext->clear();

        $created = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'V071-DEDUP',
                'objectTypeId' => $productType->getId()->toRfc4122(),
            ], JSON_THROW_ON_ERROR),
        ])->toArray();
        $id = $created['id'] ?? null;
        \assert(\is_string($id));

        $response = $client->request('GET', '/api/products/'.$id.'/effective-attribute-groups');
        self::assertResponseIsSuccessful();
        $body = $response->toArray();

        $occurrences = 0;
        $bodyGroups = $body['groups'] ?? [];
        \assert(\is_array($bodyGroups));
        foreach ($bodyGroups as $bodyGroup) {
            \assert(\is_array($bodyGroup));
            $attributes = $bodyGroup['attributes'] ?? [];
            \assert(\is_array($attributes));
            foreach ($attributes as $attr) {
                \assert(\is_array($attr));
                if ('created_at' === ($attr['code'] ?? null)) {
                    ++$occurrences;
                }
            }
        }

        self::assertSame(1, $occurrences, 'Attribute exposed in a real group must not also appear in the synthetic default group.');
    }

    #[Test]
    public function shipsAttributeOptionsForSelectAndMultiselectAttributes(): void
    {
        $client = $this->authenticatedClient();
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $productType = self::getContainer()
            ->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $productType);

        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($tenant);

        $colorAttr = new Attribute('color_view07', ['pl' => 'Kolor', 'en' => 'Color'], AttributeType::Select);
        $tagsAttr = new Attribute('tags_view07', ['pl' => 'Tagi', 'en' => 'Tags'], AttributeType::Multiselect);
        $textAttr = new Attribute('notes_view07', ['pl' => 'Notatki', 'en' => 'Notes'], AttributeType::Text);
        $em = $this->em();
        $em->persist($colorAttr);
        $em->persist($tagsAttr);
        $em->persist($textAttr);
        $em->flush();

        $em->persist(new AttributeOption(
            attribute: $colorAttr,
            code: 'red',
            label: ['pl' => 'Czerwony', 'en' => 'Red'],
            position: 0,
            color: '#EF4444',
            isDefault: true,
        ));
        $em->persist(new AttributeOption(
            attribute: $colorAttr,
            code: 'blue',
            label: ['pl' => 'Niebieski', 'en' => 'Blue'],
            position: 1,
            color: '#3B82F6',
        ));
        $em->persist(new AttributeOption(
            attribute: $tagsAttr,
            code: 'new',
            label: ['pl' => 'Nowość', 'en' => 'New'],
            position: 0,
        ));
        $em->flush();

        $service = self::getContainer()->get(ObjectTypeService::class);
        $service->assignAttribute($productType, $colorAttr, required: false, sortOrder: 0);
        $service->assignAttribute($productType, $tagsAttr, required: false, sortOrder: 1);
        $service->assignAttribute($productType, $textAttr, required: false, sortOrder: 2);

        $tenantContext->clear();

        $created = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'V071-OPTIONS',
                'objectTypeId' => $productType->getId()->toRfc4122(),
            ], JSON_THROW_ON_ERROR),
        ])->toArray();
        $id = $created['id'] ?? null;
        \assert(\is_string($id));

        $response = $client->request('GET', '/api/products/'.$id.'/effective-attribute-groups');
        self::assertResponseIsSuccessful();
        $body = $response->toArray();

        $groups = $body['groups'] ?? [];
        \assert(\is_array($groups));
        /** @var array<string, array<string, mixed>> $byCode */
        $byCode = [];
        foreach ($groups as $group) {
            \assert(\is_array($group));
            $attributes = $group['attributes'] ?? [];
            \assert(\is_array($attributes));
            foreach ($attributes as $attr) {
                \assert(\is_array($attr));
                $code = $attr['code'] ?? null;
                if (\is_string($code)) {
                    $byCode[$code] = $attr;
                }
            }
        }

        self::assertArrayHasKey('color_view07', $byCode);
        self::assertArrayHasKey('options', $byCode['color_view07']);
        $colorOptions = $byCode['color_view07']['options'];
        \assert(\is_array($colorOptions));
        self::assertCount(2, $colorOptions);
        $firstColor = $colorOptions[0];
        \assert(\is_array($firstColor));
        self::assertSame('red', $firstColor['code']);
        $firstLabel = $firstColor['label'] ?? null;
        \assert(\is_array($firstLabel));
        self::assertSame('Czerwony', $firstLabel['pl'] ?? null);
        self::assertSame('Red', $firstLabel['en'] ?? null);
        self::assertSame('#EF4444', $firstColor['color']);
        self::assertTrue($firstColor['is_default']);
        self::assertFalse($firstColor['is_deprecated']);
        $secondColor = $colorOptions[1];
        \assert(\is_array($secondColor));
        self::assertSame('blue', $secondColor['code']);

        self::assertArrayHasKey('tags_view07', $byCode);
        self::assertArrayHasKey('options', $byCode['tags_view07']);
        $tagsOptions = $byCode['tags_view07']['options'];
        \assert(\is_array($tagsOptions));
        self::assertCount(1, $tagsOptions);

        // text attribute MUST NOT carry an `options` key — option-less
        // types ignore the field and we want the payload tight.
        self::assertArrayHasKey('notes_view07', $byCode);
        self::assertArrayNotHasKey('options', $byCode['notes_view07']);
    }
}
