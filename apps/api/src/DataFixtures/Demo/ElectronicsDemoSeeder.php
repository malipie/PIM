<?php

declare(strict_types=1);

namespace App\DataFixtures\Demo;

use App\Asset\Contracts\AssetIngestorInterface;
use App\Catalog\Application\BulkContext;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\AttributeGroupAttribute;
use App\Catalog\Domain\Entity\AttributeOption;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\CategoryAttributeGroup;
use App\Catalog\Domain\Entity\ObjectCategory;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\Entity\ObjectTypeAttributeGroup;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Provenance;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\MenuConfigurationRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Catalog\Domain\Value\MenuItemRecord;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use GdImage;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

use const ARRAY_FILTER_USE_KEY;

/**
 * Electronics-shop demo dataset seeder (operator test data, 2026-07-19).
 *
 * Builds a realistic electronics catalog inside one tenant:
 *   - 20 product categories (12 roots + 8 children, ltree paths),
 *   - a custom `service` ObjectType (kind=custom) with its own 5-category
 *     tree and ~24 service objects,
 *   - ~24 new attributes on top of the base fixture set (all major
 *     AttributeType cases covered),
 *   - 14 AttributeGroups: 5 base groups attached to the Product
 *     ObjectType, 7 spec groups pinned to specific categories via
 *     `category_attribute_groups` (MOD-03 primary-category overlay), and
 *     2 groups on the service ObjectType,
 *   - N products (default 1000) with per-product generated JPEG images
 *     ingested through the Asset pipeline (MinIO + thumbnails),
 *   - PL base values + EN locale overrides on every product name and
 *     description, per-channel price overrides on a rotating subset.
 *
 * Lives in `DataFixtures` (tooling layer, deptrac-exempt) because it
 * legitimately reaches across Catalog + Asset + Shared. Invoked by
 * {@see SeedElectronicsDemoCommand}, never by fixtures — the base
 * `AppFixtures` demo catalog stays untouched.
 *
 * Batching: {@see BulkContext} flips ON to bypass the synchronous
 * `AttributesIndexedSyncListener`; `attributes_indexed` is built inline.
 * Products flush + clear every {@see self::BATCH_SIZE} rows — after each
 * clear the loop re-enters via `EntityManager::getReference()` id maps so
 * the unit of work stays bounded (architektura §3.10).
 */
final class ElectronicsDemoSeeder
{
    private const int BATCH_SIZE = 50;
    private const int RNG_SEED = 20260719;

    /** @var array<string, string> attribute code => uuid (RFC 4122) */
    private array $attributeIds = [];

    /** @var array<string, string> category code => uuid */
    private array $categoryIds = [];

    /** @var array<string, string> category code => label (ASCII-ish, for images) */
    private array $categoryImageLabels = [];

    private string $productTypeId = '';
    private string $tenantId = '';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly BulkContext $bulkContext,
        private readonly ObjectTypeRepositoryInterface $objectTypeRepository,
        private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly MenuConfigurationRepositoryInterface $menuConfigurations,
        private readonly AssetIngestorInterface $assetIngestor,
    ) {
    }

    /**
     * @param array<string, Uuid>        $channelIds channel code => id (baselinker / shopify / magento)
     * @param Closure(string): void|null $progress
     */
    public function seed(Tenant $tenant, array $channelIds, int $productCount = 1000, ?Closure $progress = null): void
    {
        $notify = $progress ?? static function (string $message): void {};
        $previous = $this->tenantContext->get();
        $this->tenantContext->set($tenant);
        $this->bulkContext->setBulk(true);
        mt_srand(self::RNG_SEED);

        try {
            $this->tenantId = $tenant->getId()->toRfc4122();
            $productType = $this->objectTypeRepository->findBuiltInByKind(ObjectKind::Product, $tenant);
            $categoryType = $this->objectTypeRepository->findBuiltInByKind(ObjectKind::Category, $tenant);
            if (null === $productType || null === $categoryType) {
                throw new RuntimeException('Built-in ObjectTypes missing — run fixtures / pim:db:reset --with-fixtures first.');
            }
            $this->productTypeId = $productType->getId()->toRfc4122();

            $attributes = $this->ensureAttributes($tenant);
            $notify(\sprintf('Attributes ready (%d).', \count($attributes)));

            $serviceType = $this->ensureServiceObjectType($tenant, $attributes);

            $productType->assignLabelAttribute($attributes['name']);
            $productType->assignImageAttribute($attributes['main_image']);
            $productType->updateCompletenessRules(['required' => ['sku', 'name', 'description', 'price']]);

            $groups = $this->ensureGroups($tenant, $attributes, $productType, $serviceType);
            $this->ensureJunctions($productType, $serviceType, $attributes);
            $this->em->flush();
            $notify(\sprintf('Attribute groups ready (%d).', \count($groups)));

            $categories = $this->seedCategories($categoryType, $productType, $serviceType, $attributes);
            $this->em->flush();
            $this->pinCategoryGroups($categories, $productType, $groups);
            $this->ensureMenuEntry($tenant, $serviceType);
            $this->em->flush();
            $notify(\sprintf('Categories ready (%d product-tree + %d service-tree).', \count(self::PRODUCT_CATEGORIES), \count(self::SERVICE_CATEGORIES)));

            foreach ($categories as $code => $category) {
                $this->categoryIds[$code] = $category->getId()->toRfc4122();
            }
            foreach ($attributes as $code => $attribute) {
                $this->attributeIds[$code] = $attribute->getId()->toRfc4122();
            }

            $this->seedServices($serviceType, $attributes, $categories, $channelIds);
            $this->em->flush();
            $notify(\sprintf('Services seeded (%d).', \count(self::SERVICES)));

            $this->seedProducts($tenant, $channelIds, $productCount, $notify);
            $notify(\sprintf('Products seeded (%d).', $productCount));
        } finally {
            $this->bulkContext->setBulk(false);
            if (null === $previous) {
                $this->tenantContext->clear();
            } else {
                $this->tenantContext->set($previous);
            }
        }
    }

    // ─── Attributes ──────────────────────────────────────────────────

    /**
     * @return array<string, Attribute>
     */
    private function ensureAttributes(Tenant $tenant): array
    {
        $attributes = [];
        $position = 100;
        foreach (self::attributeDefinitions() as $code => $def) {
            $existing = $this->attributeRepository->findByCode($code, $tenant);
            if (null !== $existing) {
                $attributes[$code] = $existing;
                continue;
            }
            $attribute = new Attribute($code, $def['label'], $def['type']);
            $attribute->changeRequired($def['required'] ?? false);
            $attribute->changeLocalizable($def['localizable'] ?? false);
            $attribute->changeScopable($def['scopable'] ?? false);
            $attribute->changeFilterable($def['filterable'] ?? false);
            $attribute->updateValidationRules($def['rules'] ?? []);
            $attribute->reorder($position++);
            $this->em->persist($attribute);
            $attributes[$code] = $attribute;

            foreach ($def['options'] ?? [] as $optPosition => $optDef) {
                $option = new AttributeOption(
                    attribute: $attribute,
                    code: $optDef[0],
                    label: $optDef[1],
                    position: $optPosition,
                    color: $optDef[2] ?? null,
                    isDefault: $optDef[3] ?? false,
                );
                $this->em->persist($option);
            }
        }
        $this->em->flush();

        return $attributes;
    }

    // ─── Service ObjectType ──────────────────────────────────────────

    /**
     * @param array<string, Attribute> $attributes
     */
    private function ensureServiceObjectType(Tenant $tenant, array $attributes): ObjectType
    {
        $existing = $this->objectTypeRepository->findByCode('service', $tenant);
        if (null !== $existing) {
            return $existing;
        }

        $type = new ObjectType('service', ObjectKind::Custom, ['pl' => 'Usługi', 'en' => 'Services']);
        $type->setIcon('Wrench');
        $type->setColor('#F59E0B');
        $type->setExposeToMainMenu(true);
        $type->setCategorizable(true);
        $type->assignLabelAttribute($attributes['name']);
        $type->updateCompletenessRules(['required' => ['sku', 'name', 'description', 'price']]);
        $this->em->persist($type);

        return $type;
    }

    // ─── Groups + junctions ──────────────────────────────────────────

    /**
     * @param array<string, Attribute> $attributes
     *
     * @return array<string, AttributeGroup>
     */
    private function ensureGroups(Tenant $tenant, array $attributes, ObjectType $productType, ObjectType $serviceType): array
    {
        $groupRepo = $this->em->getRepository(AttributeGroup::class);
        $memberRepo = $this->em->getRepository(AttributeGroupAttribute::class);
        $attachRepo = $this->em->getRepository(ObjectTypeAttributeGroup::class);

        $groups = [];
        $position = 0;
        foreach (self::groupDefinitions() as $code => $def) {
            $group = $groupRepo->findOneBy(['code' => $code, 'tenant' => $tenant]);
            if (null === $group) {
                $group = new AttributeGroup(
                    code: $code,
                    label: $def['label'],
                    position: $position,
                    icon: $def['icon'],
                    color: $def['color'],
                    isRequiredSection: $def['required_section'] ?? false,
                );
                $this->em->persist($group);
            }
            $groups[$code] = $group;

            foreach ($def['attributes'] as $memberPosition => $attributeCode) {
                $member = $memberRepo->findOneBy(['attributeGroup' => $group, 'attribute' => $attributes[$attributeCode]]);
                if (null === $member) {
                    $this->em->persist(new AttributeGroupAttribute($group, $attributes[$attributeCode], $memberPosition));
                }
            }
            ++$position;
        }
        $this->em->flush();

        // Base groups always visible on every product regardless of category.
        foreach (['basic', 'pricing', 'media', 'logistics', 'marketing'] as $attachPosition => $code) {
            $existing = $attachRepo->findOneBy(['objectType' => $productType, 'attributeGroup' => $groups[$code]]);
            if (null === $existing) {
                $this->em->persist(new ObjectTypeAttributeGroup($productType, $groups[$code], $attachPosition));
            }
        }
        foreach (['service_basic', 'service_params'] as $attachPosition => $code) {
            $existing = $attachRepo->findOneBy(['objectType' => $serviceType, 'attributeGroup' => $groups[$code]]);
            if (null === $existing) {
                $this->em->persist(new ObjectTypeAttributeGroup($serviceType, $groups[$code], $attachPosition));
            }
        }

        return $groups;
    }

    /**
     * Wire attribute junctions (completeness + list columns) for both
     * ObjectTypes, skipping codes the fixtures already attached.
     *
     * @param array<string, Attribute> $attributes
     */
    private function ensureJunctions(ObjectType $productType, ObjectType $serviceType, array $attributes): void
    {
        $junctionRepo = $this->em->getRepository(ObjectTypeAttribute::class);

        $productCodes = array_unique(array_merge(
            ...array_values(array_map(
                static fn (array $def): array => $def['attributes'],
                array_filter(self::groupDefinitions(), static fn (string $code): bool => !str_starts_with($code, 'service_'), ARRAY_FILTER_USE_KEY),
            )),
        ));
        $serviceCodes = array_unique(array_merge(
            self::groupDefinitions()['service_basic']['attributes'],
            self::groupDefinitions()['service_params']['attributes'],
        ));

        foreach ([[$productType, $productCodes], [$serviceType, $serviceCodes]] as [$type, $codes]) {
            $sort = 100;
            foreach ($codes as $code) {
                $existing = $junctionRepo->findOneBy(['objectType' => $type, 'attribute' => $attributes[$code]]);
                if (null !== $existing) {
                    continue;
                }
                $required = \in_array($code, ['sku', 'name', 'description', 'price'], true);
                $this->em->persist(new ObjectTypeAttribute($type, $attributes[$code], $required, $sort++));
            }
        }
    }

    // ─── Categories ──────────────────────────────────────────────────

    /**
     * @param array<string, Attribute> $attributes
     *
     * @return array<string, CatalogObject>
     */
    private function seedCategories(ObjectType $categoryType, ObjectType $productType, ObjectType $serviceType, array $attributes): array
    {
        $categories = [];

        foreach (self::PRODUCT_CATEGORIES as $code => $def) {
            $category = new CatalogObject($categoryType, 'CAT-'.strtoupper($code));
            $category->scopeCategoryTo($productType);
            $category->forceStatus(CatalogObject::STATUS_PUBLISHED);
            $parentCode = $def['parent'];
            $path = null === $parentCode ? $code : $parentCode.'.'.$code;
            $category->attachToPath($path);
            if (null !== $parentCode) {
                $category->assignParent($categories[$parentCode] ?? throw new RuntimeException(\sprintf('Parent category "%s" must be defined before "%s".', $parentCode, $code)));
            }
            $namePayload = ['value' => $def['label']['pl']];
            $this->em->persist(new ObjectValue($category, $attributes['name'], $namePayload, Provenance::Import));
            $this->em->persist(new ObjectValue($category, $attributes['name'], ['value' => $def['label']['en']], Provenance::Import, null, 'en'));
            $category->updateAttributeIndex(['name' => $namePayload]);
            $category->recordCompleteness(['global' => 100]);
            $this->em->persist($category);
            $categories[$code] = $category;
            $this->categoryImageLabels[$code] = $def['label']['en'];
        }

        foreach (self::SERVICE_CATEGORIES as $code => $def) {
            $category = new CatalogObject($categoryType, 'CAT-'.strtoupper($code));
            $category->scopeCategoryTo($serviceType);
            $category->forceStatus(CatalogObject::STATUS_PUBLISHED);
            $category->attachToPath($code);
            $namePayload = ['value' => $def['label']['pl']];
            $this->em->persist(new ObjectValue($category, $attributes['name'], $namePayload, Provenance::Import));
            $this->em->persist(new ObjectValue($category, $attributes['name'], ['value' => $def['label']['en']], Provenance::Import, null, 'en'));
            $category->updateAttributeIndex(['name' => $namePayload]);
            $category->recordCompleteness(['global' => 100]);
            $this->em->persist($category);
            $categories[$code] = $category;
        }

        return $categories;
    }

    /**
     * Pin spec groups to concrete categories (MOD-03 overlay): a product
     * whose primary category sits under one of these nodes inherits the
     * pinned group on top of the ObjectType base groups.
     *
     * @param array<string, CatalogObject>  $categories
     * @param array<string, AttributeGroup> $groups
     */
    private function pinCategoryGroups(array $categories, ObjectType $productType, array $groups): void
    {
        $pins = [
            'display_specs' => ['smartfony', 'laptopy', 'tablety', 'telewizory', 'monitory', 'smartwatche'],
            'computing_specs' => ['smartfony', 'laptopy', 'tablety', 'konsole'],
            'mobile_specs' => ['smartfony', 'tablety', 'smartwatche'],
            'audio_specs' => ['audio'],
            'photo_specs' => ['foto'],
            'appliance_specs' => ['agd'],
            'network_specs' => ['siec'],
        ];

        foreach ($pins as $groupCode => $categoryCodes) {
            foreach ($categoryCodes as $pinPosition => $categoryCode) {
                $this->em->persist(new CategoryAttributeGroup(
                    $categories[$categoryCode]->getId(),
                    $productType,
                    $groups[$groupCode],
                    $pinPosition,
                ));
            }
        }
    }

    private function ensureMenuEntry(Tenant $tenant, ObjectType $serviceType): void
    {
        $config = $this->menuConfigurations->findByTenant($tenant);
        if (null === $config) {
            return;
        }
        $serviceId = $serviceType->getId()->toRfc4122();
        $items = $config->getItems();
        foreach ($items as $item) {
            if (MenuItemRecord::KIND_OBJECT_TYPE === $item->kind && $item->ref === $serviceId) {
                return;
            }
        }
        // Insert Services right after Produkty (position 2), shifting the rest.
        $reordered = [];
        $position = 0;
        $inserted = false;
        foreach ($items as $item) {
            $reordered[] = new MenuItemRecord($item->kind, $item->ref, $position++, $item->visible);
            if (!$inserted && MenuItemRecord::KIND_OBJECT_TYPE === $item->kind) {
                $reordered[] = new MenuItemRecord(MenuItemRecord::KIND_OBJECT_TYPE, $serviceId, $position++, true);
                $inserted = true;
            }
        }
        if (!$inserted) {
            $reordered[] = new MenuItemRecord(MenuItemRecord::KIND_OBJECT_TYPE, $serviceId, $position, true);
        }
        $config->replaceItems($reordered);
    }

    // ─── Services ────────────────────────────────────────────────────

    /**
     * @param array<string, Attribute>     $attributes
     * @param array<string, CatalogObject> $categories
     * @param array<string, Uuid>          $channelIds
     */
    private function seedServices(ObjectType $serviceType, array $attributes, array $categories, array $channelIds): void
    {
        $index = 0;
        foreach (self::SERVICES as $def) {
            ++$index;
            $sku = \sprintf('SRV-%03d', $index);
            $service = new CatalogObject($serviceType, $sku);
            $service->forceStatus(CatalogObject::STATUS_PUBLISHED);

            $payloads = [
                'sku' => ['value' => $sku],
                'name' => ['value' => $def['name']['pl']],
                'description' => ['value' => $def['description']['pl']],
                'price' => ['amount' => $def['price'], 'currency' => 'PLN'],
                'vat_rate' => ['option_code' => 'vat_23'],
                'service_type' => ['option_code' => $def['type']],
                'service_duration' => ['value' => $def['duration'], 'unit' => 'h'],
                'sla_days' => ['value' => $def['sla']],
                'includes_parts' => ['value' => $def['parts']],
                'warranty_months' => ['value' => 12],
            ];
            $indexed = [];
            foreach ($payloads as $code => $payload) {
                $this->em->persist(new ObjectValue($service, $attributes[$code], $payload, Provenance::Import));
                $indexed[$code] = $payload;
            }
            $this->em->persist(new ObjectValue($service, $attributes['name'], ['value' => $def['name']['en']], Provenance::Import, null, 'en'));
            $this->em->persist(new ObjectValue($service, $attributes['description'], ['value' => $def['description']['en']], Provenance::Import, null, 'en'));
            if (isset($channelIds['baselinker'])) {
                $this->em->persist(new ObjectValue($service, $attributes['price'], ['amount' => round($def['price'] * 1.05, 2), 'currency' => 'PLN'], Provenance::Import, $channelIds['baselinker']));
            }

            $service->updateAttributeIndex($indexed);
            $service->recordCompleteness(['global' => 100]);
            $this->em->persist($service);
            $this->em->persist(new ObjectCategory($service, $categories[$def['category']], true));
        }
    }

    // ─── Products ────────────────────────────────────────────────────

    /**
     * @param array<string, Uuid>   $channelIds
     * @param Closure(string): void $notify
     */
    private function seedProducts(Tenant $tenant, array $channelIds, int $productCount, Closure $notify): void
    {
        // Expand the weighted category plan into a flat per-product list,
        // scaled to the requested count.
        $plan = [];
        $totalWeight = 0;
        foreach (self::PRODUCT_CATEGORIES as $code => $def) {
            $totalWeight += $def['count'];
        }
        foreach (self::PRODUCT_CATEGORIES as $code => $def) {
            if (0 === $def['count']) {
                continue;
            }
            $share = (int) round($def['count'] / $totalWeight * $productCount);
            for ($i = 0; $i < $share && \count($plan) < $productCount; ++$i) {
                $plan[] = $code;
            }
        }
        $fillerCodes = array_keys(array_filter(self::PRODUCT_CATEGORIES, static fn (array $def): bool => $def['count'] > 0));
        while (\count($plan) < $productCount) {
            $plan[] = $fillerCodes[\count($plan) % \count($fillerCodes)];
        }

        $channelIdStrings = [];
        foreach ($channelIds as $code => $id) {
            $channelIdStrings[$code] = $id->toRfc4122();
        }

        $leafCodes = $fillerCodes;
        $batch = 0;
        foreach ($plan as $i0 => $categoryCode) {
            $i = $i0 + 1;
            $this->seedOneProduct($i, $categoryCode, $leafCodes, $channelIdStrings);

            if (++$batch >= self::BATCH_SIZE) {
                $batch = 0;
                $this->em->flush();
                $this->em->clear();
                $this->refreshTenantContext();
                if (0 === $i % 200) {
                    $notify(\sprintf('  … %d / %d products', $i, $productCount));
                }
            }
        }
        $this->em->flush();
        $this->em->clear();
        $this->refreshTenantContext();
    }

    /**
     * @param list<string>          $leafCodes
     * @param array<string, string> $channelIdStrings
     */
    private function seedOneProduct(int $i, string $categoryCode, array $leafCodes, array $channelIdStrings): void
    {
        $def = self::PRODUCT_CATEGORIES[$categoryCode];
        $sku = \sprintf('ELEC-%04d', $i);
        $models = $def['models'];
        if ([] === $models) {
            throw new RuntimeException(\sprintf('Category "%s" carries products but has no model pool.', $categoryCode));
        }
        $model = self::pick($models);
        $brand = explode(' ', $model)[0];
        [$colorPl, $colorEn] = self::COLOR_WORDS[mt_rand(0, \count(self::COLOR_WORDS) - 1)];

        $variantPl = '';
        $variantEn = '';
        $extras = [];

        // Spec bundles matching the groups pinned to this category branch.
        $bundles = $def['specs'];
        if (\in_array('display', $bundles, true)) {
            $screenRange = $def['screen'] ?? [6.0, 7.0];
            $screen = mt_rand((int) ($screenRange[0] * 10), (int) ($screenRange[1] * 10)) / 10;
            $extras['screen_size'] = ['value' => $screen, 'unit' => 'in'];
            $extras['resolution'] = ['option_code' => self::pick(['fhd', 'qhd', 'uhd4k', 'uhd8k', 'hd'])];
            $extras['refresh_rate'] = ['value' => self::pick([60, 75, 90, 120, 144, 165])];
            $extras['panel_type'] = ['option_code' => self::pick(['ips', 'va', 'oled', 'amoled', 'miniled'])];
            if (\in_array($categoryCode, ['telewizory', 'monitory'], true)) {
                $variantPl = $variantEn = \sprintf('%d"', (int) $screen);
            }
        }
        if (\in_array('computing', $bundles, true)) {
            $ram = self::pick(['8gb', '16gb', '32gb', '64gb']);
            $storage = self::pick(['128gb', '256gb', '512gb', '1tb', '2tb']);
            $extras['cpu'] = ['value' => self::pick(['Snapdragon 8 Gen 3', 'Apple M3', 'Intel Core Ultra 7', 'AMD Ryzen 7 8840U', 'MediaTek Dimensity 9300', 'Intel Core i5-1340P'])];
            $extras['ram'] = ['option_code' => $ram];
            $extras['storage'] = ['option_code' => $storage];
            $extras['os'] = ['option_code' => $def['os'] ?? 'none'];
            if (\in_array($categoryCode, ['smartfony', 'tablety'], true)) {
                $variantPl = $variantEn = strtoupper($storage);
            } elseif ('laptopy' === $categoryCode) {
                $variantPl = $variantEn = \sprintf('%s/%s', strtoupper($ram), strtoupper($storage));
            }
        }
        if (\in_array('mobile', $bundles, true)) {
            $extras['battery_capacity'] = ['value' => mt_rand(30, 60) * 100];
            $extras['camera_mp'] = ['value' => self::pick([12, 48, 50, 64, 108, 200])];
            $extras['ip_rating'] = ['option_code' => self::pick(['IP54', 'IP67', 'IP68'])];
            $extras['connectivity'] = ['option_codes' => self::pickMany(['wifi', 'bt', 'nfc', 'g5', 'usb_c'], 3)];
        }
        if (\in_array('audio', $bundles, true)) {
            $extras['connectivity'] = ['option_codes' => self::pickMany(['wifi', 'bt', 'nfc', 'usb_c'], 2)];
            $extras['power_w'] = ['value' => mt_rand(5, 120), 'unit' => 'W'];
            $extras['noise_level'] = ['value' => mt_rand(20, 40)];
        }
        if (\in_array('photo', $bundles, true)) {
            $extras['camera_mp'] = ['value' => self::pick([20, 24, 26, 33, 45, 61])];
            $extras['sensor_type'] = ['option_code' => self::pick(['aps_c', 'full_frame', 'micro43'])];
            $extras['optical_zoom'] = ['value' => self::pick([0, 3, 5, 10])];
        }
        if (\in_array('appliance', $bundles, true)) {
            $extras['energy_class'] = ['option_code' => self::pick(['a', 'b', 'c', 'd', 'e'])];
            $extras['power_w'] = ['value' => mt_rand(40, 2200), 'unit' => 'W'];
            $extras['voltage'] = ['value' => 230, 'unit' => 'V'];
            $extras['noise_level'] = ['value' => mt_rand(35, 75)];
            if ('lodowki' === $categoryCode) {
                $extras['capacity_l'] = ['value' => mt_rand(200, 450), 'unit' => 'l'];
            }
            if ('pralki' === $categoryCode) {
                $extras['capacity_l'] = ['value' => mt_rand(6, 12), 'unit' => 'kg'];
                $extras['spin_speed'] = ['value' => self::pick([1000, 1200, 1400, 1600])];
            }
        }
        if (\in_array('network', $bundles, true)) {
            $extras['wifi_standard'] = ['option_code' => self::pick(['wifi5', 'wifi6', 'wifi6e', 'wifi7'])];
            $extras['lan_ports'] = ['value' => self::pick([1, 2, 4, 8])];
            $extras['connectivity'] = ['option_codes' => ['wifi', 'ethernet']];
        }

        $namePl = implode(' ', array_filter([$model, $variantPl, $colorPl], static fn (string $part): bool => '' !== $part));
        $nameEn = implode(' ', array_filter([$model, $variantEn, $colorEn], static fn (string $part): bool => '' !== $part));
        $price = round(mt_rand($def['price'][0] * 100, $def['price'][1] * 100) / 100, 2);
        $weight = round(mt_rand((int) ($def['weight'][0] * 1000), (int) ($def['weight'][1] * 1000)) / 1000, 3);
        $withRichContent = 0 !== $i % 8;

        // Generated placeholder image → real bytes in MinIO + thumbnails.
        // Must run BEFORE any entity for this product is built: with the
        // dev sync transport the thumbnail handler runs inline and clears
        // the EntityManager mid-ingest.
        $assetId = $this->ingestProductImage($sku, $model, $this->categoryImageLabels[$categoryCode] ?? $categoryCode, $def['hue']);
        $this->refreshTenantContext();

        $productType = $this->em->getReference(ObjectType::class, Uuid::fromString($this->productTypeId)) ?? throw new RuntimeException('Product ObjectType reference unavailable.');
        $product = new CatalogObject($productType, $sku);
        $status = CatalogObject::STATUS_PUBLISHED;
        if (0 === $i % 17) {
            $status = CatalogObject::STATUS_DRAFT;
        } elseif (0 === $i % 29) {
            $status = CatalogObject::STATUS_REVIEW;
        } elseif (0 === $i % 53) {
            $status = CatalogObject::STATUS_ARCHIVED;
        }
        $product->forceStatus($status);

        $descPl = \sprintf('%s marki %s z kategorii %s. Solidne wykonanie, nowoczesne funkcje i %d miesięcy gwarancji. Indeks katalogowy %s.', $namePl, $brand, self::PRODUCT_CATEGORIES[$categoryCode]['label']['pl'], 24, $sku);
        $descEn = \sprintf('%s by %s from the %s category. Solid build, modern features and a 24-month warranty. Catalog index %s.', $nameEn, $brand, self::PRODUCT_CATEGORIES[$categoryCode]['label']['en'], $sku);

        $payloads = [
            'sku' => ['value' => $sku],
            'ean' => ['value' => \sprintf('590%010d', ($i * 7919) % 10_000_000_000)],
            'name' => ['value' => $namePl],
            'description' => ['value' => $descPl],
            'short_description' => ['value' => \sprintf('%s — %s', $namePl, self::PRODUCT_CATEGORIES[$categoryCode]['label']['pl'])],
            'brand' => ['value' => $brand],
            'vat_rate' => ['option_code' => 'vat_23'],
            'tags' => ['option_codes' => self::pickMany(['new', 'sale', 'eco', 'premium'], 1 + ($i % 2))],
            'price' => ['amount' => $price, 'currency' => 'PLN'],
            'weight' => ['value' => $weight, 'unit' => 'kg'],
            'in_stock' => ['value' => 0 !== $i % 9],
            'release_date' => ['value' => \sprintf('%d-%02d-%02d', 2024 + ($i % 3), 1 + ($i % 12), 1 + ($i % 28))],
            'warranty_months' => ['value' => self::pick([12, 24, 36])],
            'main_image' => ['asset_id' => $assetId],
        ];
        if ($withRichContent) {
            $payloads['description_html'] = ['value' => \sprintf('<h2>%s</h2><p>%s</p><ul><li>Marka: %s</li><li>Gwarancja: 24 mies.</li></ul>', htmlspecialchars($namePl), htmlspecialchars($descPl), htmlspecialchars($brand))];
            $payloads['eol_date'] = 0 === $i % 5 ? ['value' => \sprintf('%d-12-31', 2027 + ($i % 3))] : null;
        }
        $payloads = array_filter($payloads, static fn (?array $p): bool => null !== $p);
        $payloads = [...$payloads, ...$extras];

        $indexed = [];
        foreach ($payloads as $code => $payload) {
            $this->em->persist(new ObjectValue($product, $this->attrRef($code), $payload, Provenance::Import));
            $indexed[$code] = $payload;
        }

        // EN locale overrides — every product carries both language versions.
        $this->em->persist(new ObjectValue($product, $this->attrRef('name'), ['value' => $nameEn], Provenance::Import, null, 'en'));
        $this->em->persist(new ObjectValue($product, $this->attrRef('description'), ['value' => $descEn], Provenance::Import, null, 'en'));

        // Per-channel price overrides on a rotating subset (~2/3 of products).
        if (0 === $i % 2 && isset($channelIdStrings['baselinker'])) {
            $this->em->persist(new ObjectValue($product, $this->attrRef('price'), ['amount' => round($price * 1.03, 2), 'currency' => 'PLN'], Provenance::Import, Uuid::fromString($channelIdStrings['baselinker'])));
        }
        if (0 === $i % 3 && isset($channelIdStrings['shopify'])) {
            $this->em->persist(new ObjectValue($product, $this->attrRef('price'), ['amount' => round($price / 4.3, 2), 'currency' => 'EUR'], Provenance::Import, Uuid::fromString($channelIdStrings['shopify'])));
        }
        if (0 === $i % 5 && isset($channelIdStrings['magento'])) {
            $this->em->persist(new ObjectValue($product, $this->attrRef('price'), ['amount' => round($price * 0.95, 2), 'currency' => 'PLN'], Provenance::Import, Uuid::fromString($channelIdStrings['magento'])));
        }
        if (0 === $i % 7 && isset($channelIdStrings['shopify'])) {
            $this->em->persist(new ObjectValue($product, $this->attrRef('short_description'), ['value' => \sprintf('%s — Shopify exclusive.', $nameEn)], Provenance::Import, Uuid::fromString($channelIdStrings['shopify'])));
        }

        $product->updateAttributeIndex($indexed);
        $product->recordCompleteness(['global' => $withRichContent ? 100 : 75]);
        $this->em->persist($product);

        // Primary category + occasionally a secondary classification.
        $this->em->persist(new ObjectCategory($product, $this->categoryRef($categoryCode), true));
        if (0 === $i % 10) {
            $secondary = $leafCodes[intdiv($i, 10) % \count($leafCodes)];
            if ($secondary !== $categoryCode) {
                $this->em->persist(new ObjectCategory($product, $this->categoryRef($secondary), false, 1));
            }
        }
    }

    // ─── Image generation ────────────────────────────────────────────

    /**
     * Render an 800×600 JPEG placeholder (category-hued background, white
     * card, product text) and push it through the real Asset pipeline.
     * Returns the Asset id (RFC 4122) for the `main_image` payload.
     */
    private function ingestProductImage(string $sku, string $title, string $categoryLabel, int $hue): string
    {
        $im = imagecreatetruecolor(800, 600);
        if (false === $im) {
            throw new RuntimeException('imagecreatetruecolor failed.');
        }
        [$r, $g, $b] = self::hsvToRgb($hue, 0.35, 0.92);
        imagefilledrectangle($im, 0, 0, 800, 600, self::color($im, $r, $g, $b));

        [$r, $g, $b] = self::hsvToRgb($hue, 0.55, 0.72);
        imagefilledrectangle($im, 0, 0, 800, 90, self::color($im, $r, $g, $b));

        $white = self::color($im, 255, 255, 255);
        imagefilledrectangle($im, 60, 150, 740, 470, $white);

        [$r, $g, $b] = self::hsvToRgb($hue, 0.65, 0.55);
        imagefilledellipse($im, 400, 280, 170, 170, self::color($im, $r, $g, $b));

        $dark = self::color($im, 30, 34, 42);
        $ascii = static fn (string $text): string => (string) preg_replace('/[^\x20-\x7E]/', '', $text);
        imagestring($im, 5, 24, 36, $ascii($categoryLabel), $white);
        imagestring($im, 5, 80, 500, $ascii($title), $dark);
        imagestring($im, 4, 80, 530, $sku, $dark);

        $tmp = tempnam(sys_get_temp_dir(), 'pim-elec-');
        if (false === $tmp) {
            throw new RuntimeException('Failed to allocate a temp file for image generation.');
        }
        imagejpeg($im, $tmp, 82);
        imagedestroy($im);

        try {
            $result = $this->assetIngestor->ingest($tmp, strtolower($sku).'.jpg');

            return $result->assetId->toRfc4122();
        } finally {
            @unlink($tmp);
        }
    }

    private static function color(GdImage $im, int $r, int $g, int $b): int
    {
        $color = imagecolorallocate($im, max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
        if (false === $color) {
            throw new RuntimeException('imagecolorallocate failed.');
        }

        return $color;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function hsvToRgb(int $hue, float $saturation, float $value): array
    {
        $c = $value * $saturation;
        $x = $c * (1 - abs(fmod($hue / 60, 2) - 1));
        $m = $value - $c;
        [$r, $g, $b] = match (intdiv($hue % 360, 60)) {
            0 => [$c, $x, 0.0],
            1 => [$x, $c, 0.0],
            2 => [0.0, $c, $x],
            3 => [0.0, $x, $c],
            4 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };

        return [(int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255)];
    }

    // ─── Reference helpers (survive EntityManager::clear) ────────────

    /**
     * Re-set the TenantContext with a MANAGED Tenant instance. Needed after
     * every `EntityManager::clear()` — including the clear() performed by
     * the inline (sync transport) thumbnail message handler that runs
     * inside {@see AssetIngestorInterface::ingest} — otherwise the
     * TenantAssignmentListener stamps new entities with a detached Tenant
     * and the next flush dies with "new entity found through relationship".
     */
    private function refreshTenantContext(): void
    {
        $current = $this->tenantContext->get();
        if (null !== $current && $this->em->contains($current)) {
            return;
        }
        $fresh = $this->em->find(Tenant::class, Uuid::fromString($this->tenantId));
        if (null === $fresh) {
            throw new RuntimeException('Tenant disappeared mid-seed.');
        }
        $this->tenantContext->set($fresh);
    }

    private function attrRef(string $code): Attribute
    {
        return $this->em->getReference(Attribute::class, Uuid::fromString($this->attributeIds[$code]))
            ?? throw new RuntimeException(\sprintf('Attribute reference "%s" unavailable.', $code));
    }

    private function categoryRef(string $code): CatalogObject
    {
        return $this->em->getReference(CatalogObject::class, Uuid::fromString($this->categoryIds[$code]))
            ?? throw new RuntimeException(\sprintf('Category reference "%s" unavailable.', $code));
    }

    /**
     * @template T
     *
     * @param non-empty-list<T> $values
     *
     * @return T
     */
    private static function pick(array $values)
    {
        return $values[mt_rand(0, \count($values) - 1)];
    }

    /**
     * @param non-empty-list<string> $values
     *
     * @return list<string>
     */
    private static function pickMany(array $values, int $count): array
    {
        $keys = (array) array_rand($values, min($count, \count($values)));
        $picked = [];
        foreach ($keys as $key) {
            $picked[] = $values[$key];
        }

        return $picked;
    }

    // ─── Static definitions ──────────────────────────────────────────

    /** @var array{0: string, 1: string}[] */
    private const array COLOR_WORDS = [
        ['czarny', 'Black'],
        ['biały', 'White'],
        ['srebrny', 'Silver'],
        ['niebieski', 'Blue'],
        ['grafitowy', 'Graphite'],
    ];

    /**
     * Product category tree. Key = ltree segment (ASCII). `count` is the
     * weight used to distribute products; 0 = structural node only.
     *
     * @var array<string, array{label: array{pl: string, en: string}, parent: ?string, count: int, hue: int, price: array{0: int, 1: int}, weight: array{0: float, 1: float}, models: list<string>, specs: list<string>, screen?: array{0: float, 1: float}, os?: string}>
     */
    private const array PRODUCT_CATEGORIES = [
        'smartfony' => ['label' => ['pl' => 'Smartfony', 'en' => 'Smartphones'], 'parent' => null, 'count' => 120, 'hue' => 210, 'price' => [899, 6999], 'weight' => [0.15, 0.25], 'specs' => ['display', 'computing', 'mobile'], 'screen' => [6.1, 6.9], 'os' => 'android', 'models' => ['Samsung Galaxy S24', 'Samsung Galaxy A55', 'Apple iPhone 15', 'Apple iPhone 14', 'Xiaomi Redmi Note 13', 'Google Pixel 8', 'OnePlus 12', 'Motorola Edge 50']],
        'laptopy' => ['label' => ['pl' => 'Laptopy', 'en' => 'Laptops'], 'parent' => null, 'count' => 110, 'hue' => 260, 'price' => [1999, 12999], 'weight' => [1.1, 2.8], 'specs' => ['display', 'computing'], 'screen' => [13.0, 17.0], 'os' => 'windows', 'models' => ['Lenovo ThinkPad T14', 'Lenovo IdeaPad 5', 'Dell XPS 13', 'Dell Inspiron 16', 'HP EliteBook 840', 'HP Pavilion 15', 'Apple MacBook Air M3', 'Asus ZenBook 14', 'Acer Aspire 5']],
        'tablety' => ['label' => ['pl' => 'Tablety', 'en' => 'Tablets'], 'parent' => null, 'count' => 60, 'hue' => 190, 'price' => [599, 5499], 'weight' => [0.3, 0.7], 'specs' => ['display', 'computing', 'mobile'], 'screen' => [8.0, 13.0], 'os' => 'android', 'models' => ['Samsung Galaxy Tab S9', 'Apple iPad Air', 'Apple iPad 10', 'Lenovo Tab P12', 'Xiaomi Pad 6']],
        'telewizory' => ['label' => ['pl' => 'Telewizory', 'en' => 'TVs'], 'parent' => null, 'count' => 90, 'hue' => 0, 'price' => [1299, 14999], 'weight' => [8.0, 35.0], 'specs' => ['display'], 'screen' => [43.0, 85.0], 'models' => ['Samsung QLED Q80D', 'LG OLED C4', 'Sony Bravia X90L', 'TCL C745', 'Philips Ambilight OLED808']],
        'monitory' => ['label' => ['pl' => 'Monitory', 'en' => 'Monitors'], 'parent' => null, 'count' => 70, 'hue' => 30, 'price' => [499, 4999], 'weight' => [3.0, 9.0], 'specs' => ['display'], 'screen' => [24.0, 34.0], 'models' => ['Dell UltraSharp U2723QE', 'LG UltraGear 27GP850', 'Samsung Odyssey G7', 'iiyama ProLite XUB2792', 'BenQ PD2705U']],
        'audio' => ['label' => ['pl' => 'Audio', 'en' => 'Audio'], 'parent' => null, 'count' => 0, 'hue' => 120, 'price' => [0, 0], 'weight' => [0.0, 0.0], 'specs' => [], 'models' => []],
        'sluchawki' => ['label' => ['pl' => 'Słuchawki', 'en' => 'Headphones'], 'parent' => 'audio', 'count' => 80, 'hue' => 130, 'price' => [99, 1899], 'weight' => [0.05, 0.4], 'specs' => ['audio'], 'models' => ['Sony WH-1000XM5', 'Bose QuietComfort 45', 'Apple AirPods Pro 2', 'JBL Tune 770NC', 'Sennheiser Momentum 4']],
        'glosniki' => ['label' => ['pl' => 'Głośniki', 'en' => 'Speakers'], 'parent' => 'audio', 'count' => 60, 'hue' => 150, 'price' => [149, 2999], 'weight' => [0.5, 5.0], 'specs' => ['audio'], 'models' => ['JBL Charge 5', 'Sonos One SL', 'Marshall Stanmore III', 'Bose SoundLink Flex', 'Tribit StormBox Blast']],
        'foto' => ['label' => ['pl' => 'Fotografia', 'en' => 'Photography'], 'parent' => null, 'count' => 0, 'hue' => 280, 'price' => [0, 0], 'weight' => [0.0, 0.0], 'specs' => [], 'models' => []],
        'aparaty' => ['label' => ['pl' => 'Aparaty', 'en' => 'Cameras'], 'parent' => 'foto', 'count' => 60, 'hue' => 290, 'price' => [1999, 15999], 'weight' => [0.3, 1.2], 'specs' => ['photo'], 'models' => ['Canon EOS R50', 'Nikon Z50', 'Sony A6400', 'Fujifilm X-T30 II', 'Panasonic Lumix G100']],
        'gaming' => ['label' => ['pl' => 'Gaming', 'en' => 'Gaming'], 'parent' => null, 'count' => 0, 'hue' => 330, 'price' => [0, 0], 'weight' => [0.0, 0.0], 'specs' => [], 'models' => []],
        'konsole' => ['label' => ['pl' => 'Konsole', 'en' => 'Consoles'], 'parent' => 'gaming', 'count' => 50, 'hue' => 340, 'price' => [999, 3499], 'weight' => [1.5, 4.5], 'specs' => ['computing'], 'os' => 'none', 'models' => ['Sony PlayStation 5', 'Sony PlayStation 5 Digital', 'Microsoft Xbox Series X', 'Microsoft Xbox Series S', 'Nintendo Switch OLED']],
        'smartwatche' => ['label' => ['pl' => 'Smartwatche', 'en' => 'Smartwatches'], 'parent' => null, 'count' => 70, 'hue' => 50, 'price' => [299, 3999], 'weight' => [0.03, 0.09], 'specs' => ['display', 'mobile'], 'screen' => [1.2, 2.0], 'models' => ['Apple Watch Series 9', 'Samsung Galaxy Watch 6', 'Garmin Venu 3', 'Huawei Watch GT 4', 'Amazfit GTR 4']],
        'agd' => ['label' => ['pl' => 'AGD', 'en' => 'Home appliances'], 'parent' => null, 'count' => 0, 'hue' => 90, 'price' => [0, 0], 'weight' => [0.0, 0.0], 'specs' => [], 'models' => []],
        'lodowki' => ['label' => ['pl' => 'Lodówki', 'en' => 'Refrigerators'], 'parent' => 'agd', 'count' => 50, 'hue' => 95, 'price' => [1499, 8999], 'weight' => [55.0, 120.0], 'specs' => ['appliance'], 'models' => ['Samsung RB38C', 'Bosch KGN39', 'LG GBB72', 'Whirlpool W7X 82', 'Beko B5RCNA405']],
        'pralki' => ['label' => ['pl' => 'Pralki', 'en' => 'Washing machines'], 'parent' => 'agd', 'count' => 50, 'hue' => 100, 'price' => [1199, 4999], 'weight' => [60.0, 85.0], 'specs' => ['appliance'], 'models' => ['Bosch WAN2822', 'Samsung WW90', 'LG F4WV329', 'Whirlpool FFB 8258', 'Electrolux EW6F348']],
        'odkurzacze' => ['label' => ['pl' => 'Odkurzacze', 'en' => 'Vacuum cleaners'], 'parent' => 'agd', 'count' => 50, 'hue' => 110, 'price' => [299, 3599], 'weight' => [1.5, 8.0], 'specs' => ['appliance'], 'models' => ['Dyson V15 Detect', 'Xiaomi G10 Plus', 'Tefal X-Force Flex', 'Philips SpeedPro Max', 'Bosch Unlimited 7']],
        'akcesoria' => ['label' => ['pl' => 'Akcesoria', 'en' => 'Accessories'], 'parent' => null, 'count' => 50, 'hue' => 20, 'price' => [29, 899], 'weight' => [0.05, 1.0], 'specs' => [], 'models' => ['Logitech MX Master 3S', 'Keychron K8 Pro', 'Anker PowerCore 20000', 'Belkin BoostCharge 65W', 'SanDisk Ultra 128GB', 'Baseus Hub USB-C 8w1']],
        'siec' => ['label' => ['pl' => 'Sieć i internet', 'en' => 'Networking'], 'parent' => null, 'count' => 0, 'hue' => 170, 'price' => [0, 0], 'weight' => [0.0, 0.0], 'specs' => [], 'models' => []],
        'routery' => ['label' => ['pl' => 'Routery', 'en' => 'Routers'], 'parent' => 'siec', 'count' => 30, 'hue' => 175, 'price' => [149, 1999], 'weight' => [0.3, 1.2], 'specs' => ['network'], 'models' => ['TP-Link Archer AX55', 'Asus RT-AX58U', 'Netgear Nighthawk RAX43', 'Xiaomi AX3200', 'Ubiquiti UniFi Dream Router']],
    ];

    /**
     * @var array<string, array{label: array{pl: string, en: string}}>
     */
    private const array SERVICE_CATEGORIES = [
        'srv_montaz' => ['label' => ['pl' => 'Montaż i instalacja', 'en' => 'Installation']],
        'srv_serwis' => ['label' => ['pl' => 'Serwis i naprawa', 'en' => 'Repair services']],
        'srv_konfiguracja' => ['label' => ['pl' => 'Konfiguracja i wdrożenie', 'en' => 'Setup & onboarding']],
        'srv_gwarancje' => ['label' => ['pl' => 'Gwarancje rozszerzone', 'en' => 'Extended warranties']],
        'srv_szkolenia' => ['label' => ['pl' => 'Szkolenia', 'en' => 'Trainings']],
    ];

    /**
     * @var list<array{category: string, name: array{pl: string, en: string}, description: array{pl: string, en: string}, price: float, type: string, duration: float, sla: int, parts: bool}>
     */
    private const array SERVICES = [
        ['category' => 'srv_montaz', 'name' => ['pl' => 'Montaż telewizora na ścianie', 'en' => 'Wall-mount TV installation'], 'description' => ['pl' => 'Profesjonalny montaż telewizora do 85" wraz z uchwytem i maskowaniem kabli.', 'en' => 'Professional wall mounting of TVs up to 85", bracket and cable management included.'], 'price' => 249.0, 'type' => 'onsite', 'duration' => 2.0, 'sla' => 3, 'parts' => true],
        ['category' => 'srv_montaz', 'name' => ['pl' => 'Instalacja sprzętu AGD', 'en' => 'Home appliance installation'], 'description' => ['pl' => 'Podłączenie pralki, zmywarki lub lodówki wraz z testem szczelności.', 'en' => 'Connection of a washing machine, dishwasher or fridge with a leak test.'], 'price' => 179.0, 'type' => 'onsite', 'duration' => 1.5, 'sla' => 3, 'parts' => false],
        ['category' => 'srv_montaz', 'name' => ['pl' => 'Montaż sieci domowej', 'en' => 'Home network installation'], 'description' => ['pl' => 'Rozprowadzenie okablowania i konfiguracja routera oraz access pointów.', 'en' => 'Cabling plus router and access point configuration.'], 'price' => 399.0, 'type' => 'onsite', 'duration' => 4.0, 'sla' => 5, 'parts' => true],
        ['category' => 'srv_montaz', 'name' => ['pl' => 'Instalacja soundbaru', 'en' => 'Soundbar installation'], 'description' => ['pl' => 'Montaż i kalibracja soundbaru z subwooferem.', 'en' => 'Mounting and calibration of a soundbar with subwoofer.'], 'price' => 149.0, 'type' => 'onsite', 'duration' => 1.0, 'sla' => 3, 'parts' => false],
        ['category' => 'srv_serwis', 'name' => ['pl' => 'Diagnoza laptopa', 'en' => 'Laptop diagnostics'], 'description' => ['pl' => 'Pełna diagnostyka sprzętowa i programowa laptopa z raportem.', 'en' => 'Full hardware and software laptop diagnostics with a report.'], 'price' => 99.0, 'type' => 'workshop', 'duration' => 1.0, 'sla' => 2, 'parts' => false],
        ['category' => 'srv_serwis', 'name' => ['pl' => 'Wymiana ekranu smartfona', 'en' => 'Smartphone screen replacement'], 'description' => ['pl' => 'Wymiana wyświetlacza z użyciem oryginalnych części.', 'en' => 'Display replacement using original parts.'], 'price' => 449.0, 'type' => 'workshop', 'duration' => 1.5, 'sla' => 2, 'parts' => true],
        ['category' => 'srv_serwis', 'name' => ['pl' => 'Wymiana baterii smartfona', 'en' => 'Smartphone battery replacement'], 'description' => ['pl' => 'Wymiana ogniwa wraz z kalibracją i testem pojemności.', 'en' => 'Battery swap with calibration and capacity test.'], 'price' => 199.0, 'type' => 'workshop', 'duration' => 1.0, 'sla' => 2, 'parts' => true],
        ['category' => 'srv_serwis', 'name' => ['pl' => 'Czyszczenie i konserwacja laptopa', 'en' => 'Laptop cleaning & maintenance'], 'description' => ['pl' => 'Czyszczenie układu chłodzenia, wymiana pasty termoprzewodzącej.', 'en' => 'Cooling system cleaning and thermal paste replacement.'], 'price' => 149.0, 'type' => 'workshop', 'duration' => 1.5, 'sla' => 3, 'parts' => true],
        ['category' => 'srv_serwis', 'name' => ['pl' => 'Serwis ekspresowy AGD', 'en' => 'Express appliance repair'], 'description' => ['pl' => 'Naprawa sprzętu AGD u klienta w 48 godzin.', 'en' => 'On-site appliance repair within 48 hours.'], 'price' => 299.0, 'type' => 'onsite', 'duration' => 2.0, 'sla' => 2, 'parts' => false],
        ['category' => 'srv_konfiguracja', 'name' => ['pl' => 'Konfiguracja nowego komputera', 'en' => 'New PC setup'], 'description' => ['pl' => 'Instalacja systemu, sterowników i pakietu aplikacji startowych.', 'en' => 'OS, driver and starter app installation.'], 'price' => 149.0, 'type' => 'remote', 'duration' => 2.0, 'sla' => 1, 'parts' => false],
        ['category' => 'srv_konfiguracja', 'name' => ['pl' => 'Konfiguracja routera i Wi-Fi', 'en' => 'Router & Wi-Fi setup'], 'description' => ['pl' => 'Bezpieczna konfiguracja sieci domowej z optymalizacją zasięgu.', 'en' => 'Secure home network setup with coverage optimisation.'], 'price' => 119.0, 'type' => 'remote', 'duration' => 1.0, 'sla' => 1, 'parts' => false],
        ['category' => 'srv_konfiguracja', 'name' => ['pl' => 'Przeniesienie danych na nowy telefon', 'en' => 'Phone data migration'], 'description' => ['pl' => 'Migracja kontaktów, zdjęć i aplikacji między urządzeniami.', 'en' => 'Migration of contacts, photos and apps between devices.'], 'price' => 89.0, 'type' => 'workshop', 'duration' => 1.0, 'sla' => 1, 'parts' => false],
        ['category' => 'srv_konfiguracja', 'name' => ['pl' => 'Konfiguracja smart home', 'en' => 'Smart home setup'], 'description' => ['pl' => 'Integracja urządzeń smart home z aplikacją i automatyzacjami.', 'en' => 'Smart home device integration with app and automations.'], 'price' => 349.0, 'type' => 'onsite', 'duration' => 3.0, 'sla' => 5, 'parts' => false],
        ['category' => 'srv_gwarancje', 'name' => ['pl' => 'Gwarancja rozszerzona +12 miesięcy', 'en' => 'Extended warranty +12 months'], 'description' => ['pl' => 'Dodatkowy rok ochrony serwisowej po gwarancji producenta.', 'en' => 'An extra year of coverage after the manufacturer warranty.'], 'price' => 199.0, 'type' => 'remote', 'duration' => 0.5, 'sla' => 30, 'parts' => false],
        ['category' => 'srv_gwarancje', 'name' => ['pl' => 'Gwarancja rozszerzona +24 miesiące', 'en' => 'Extended warranty +24 months'], 'description' => ['pl' => 'Dwa dodatkowe lata ochrony serwisowej i door-to-door.', 'en' => 'Two extra years of coverage with door-to-door service.'], 'price' => 349.0, 'type' => 'remote', 'duration' => 0.5, 'sla' => 30, 'parts' => false],
        ['category' => 'srv_gwarancje', 'name' => ['pl' => 'Ochrona przed przypadkowym uszkodzeniem', 'en' => 'Accidental damage protection'], 'description' => ['pl' => 'Roczna ochrona obejmująca zalanie i upadki urządzenia.', 'en' => 'One-year protection covering liquid damage and drops.'], 'price' => 299.0, 'type' => 'remote', 'duration' => 0.5, 'sla' => 30, 'parts' => true],
        ['category' => 'srv_szkolenia', 'name' => ['pl' => 'Szkolenie z obsługi smartfona', 'en' => 'Smartphone basics training'], 'description' => ['pl' => 'Indywidualne szkolenie z podstaw obsługi smartfona dla seniorów.', 'en' => 'One-on-one smartphone basics training for seniors.'], 'price' => 129.0, 'type' => 'onsite', 'duration' => 2.0, 'sla' => 7, 'parts' => false],
        ['category' => 'srv_szkolenia', 'name' => ['pl' => 'Warsztaty fotograficzne', 'en' => 'Photography workshop'], 'description' => ['pl' => 'Warsztaty z obsługi aparatu bezlusterkowego i podstaw kompozycji.', 'en' => 'Mirrorless camera handling and composition basics workshop.'], 'price' => 249.0, 'type' => 'workshop', 'duration' => 4.0, 'sla' => 14, 'parts' => false],
        ['category' => 'srv_szkolenia', 'name' => ['pl' => 'Szkolenie ze smart home', 'en' => 'Smart home training'], 'description' => ['pl' => 'Praktyczne szkolenie z automatyzacji domu i scen świetlnych.', 'en' => 'Hands-on home automation and lighting scene training.'], 'price' => 199.0, 'type' => 'remote', 'duration' => 2.0, 'sla' => 7, 'parts' => false],
        ['category' => 'srv_szkolenia', 'name' => ['pl' => 'Szkolenie z bezpieczeństwa w sieci', 'en' => 'Online safety training'], 'description' => ['pl' => 'Szkolenie z haseł, menedżerów haseł i rozpoznawania phishingu.', 'en' => 'Passwords, password managers and phishing awareness training.'], 'price' => 159.0, 'type' => 'remote', 'duration' => 1.5, 'sla' => 7, 'parts' => false],
    ];

    /**
     * @return array<string, array{label: array{pl: string, en: string}, icon: ?string, color: ?string, required_section?: bool, attributes: list<string>}>
     */
    private static function groupDefinitions(): array
    {
        return [
            'basic' => ['label' => ['pl' => 'Dane podstawowe', 'en' => 'Basic data'], 'icon' => 'Info', 'color' => '#3B82F6', 'required_section' => true, 'attributes' => ['name', 'sku', 'ean', 'brand', 'vat_rate', 'short_description', 'description']],
            'pricing' => ['label' => ['pl' => 'Ceny', 'en' => 'Pricing'], 'icon' => 'Banknote', 'color' => '#10B981', 'attributes' => ['price']],
            'media' => ['label' => ['pl' => 'Multimedia', 'en' => 'Media'], 'icon' => 'Image', 'color' => '#8B5CF6', 'attributes' => ['main_image']],
            'logistics' => ['label' => ['pl' => 'Logistyka i stan', 'en' => 'Logistics & stock'], 'icon' => 'Truck', 'color' => '#F97316', 'attributes' => ['weight', 'in_stock', 'warranty_months']],
            'marketing' => ['label' => ['pl' => 'Marketing', 'en' => 'Marketing'], 'icon' => 'Megaphone', 'color' => '#EC4899', 'attributes' => ['description_html', 'tags', 'release_date', 'eol_date']],
            'display_specs' => ['label' => ['pl' => 'Ekran', 'en' => 'Display'], 'icon' => 'Monitor', 'color' => '#0EA5E9', 'attributes' => ['screen_size', 'resolution', 'refresh_rate', 'panel_type']],
            'computing_specs' => ['label' => ['pl' => 'Podzespoły', 'en' => 'Computing'], 'icon' => 'Cpu', 'color' => '#6366F1', 'attributes' => ['cpu', 'ram', 'storage', 'os']],
            'mobile_specs' => ['label' => ['pl' => 'Parametry mobilne', 'en' => 'Mobile specs'], 'icon' => 'Smartphone', 'color' => '#14B8A6', 'attributes' => ['battery_capacity', 'camera_mp', 'ip_rating', 'connectivity']],
            'audio_specs' => ['label' => ['pl' => 'Parametry audio', 'en' => 'Audio specs'], 'icon' => 'Volume2', 'color' => '#22C55E', 'attributes' => ['connectivity', 'power_w', 'noise_level']],
            'photo_specs' => ['label' => ['pl' => 'Parametry foto', 'en' => 'Photo specs'], 'icon' => 'Camera', 'color' => '#A855F7', 'attributes' => ['camera_mp', 'sensor_type', 'optical_zoom']],
            'appliance_specs' => ['label' => ['pl' => 'Parametry AGD', 'en' => 'Appliance specs'], 'icon' => 'Plug', 'color' => '#84CC16', 'attributes' => ['energy_class', 'capacity_l', 'spin_speed', 'noise_level', 'power_w', 'voltage']],
            'network_specs' => ['label' => ['pl' => 'Parametry sieciowe', 'en' => 'Network specs'], 'icon' => 'Wifi', 'color' => '#06B6D4', 'attributes' => ['wifi_standard', 'lan_ports', 'connectivity']],
            'service_basic' => ['label' => ['pl' => 'Dane usługi', 'en' => 'Service data'], 'icon' => 'Info', 'color' => '#F59E0B', 'required_section' => true, 'attributes' => ['name', 'sku', 'description', 'price', 'vat_rate']],
            'service_params' => ['label' => ['pl' => 'Parametry usługi', 'en' => 'Service parameters'], 'icon' => 'Wrench', 'color' => '#F97316', 'attributes' => ['service_type', 'service_duration', 'sla_days', 'includes_parts', 'warranty_months']],
        ];
    }

    /**
     * Attribute catalog — codes already seeded by the base fixtures are
     * looked up first; definitions below only materialise when missing,
     * so the seeder also works on a non-fixtures database.
     *
     * @return array<string, array{label: array<string, string>, type: AttributeType, required?: bool, localizable?: bool, scopable?: bool, filterable?: bool, rules?: array<string, mixed>, options?: list<array{0: string, 1: array<string, string>, 2?: ?string, 3?: bool}>}>
     */
    private static function attributeDefinitions(): array
    {
        return [
            // ── Reused base attributes (fixtures usually provide these) ──
            'name' => ['label' => ['pl' => 'Nazwa', 'en' => 'Name'], 'type' => AttributeType::Text, 'required' => true, 'localizable' => true, 'rules' => ['max_length' => 255]],
            'sku' => ['label' => ['pl' => 'SKU', 'en' => 'SKU'], 'type' => AttributeType::Text, 'required' => true, 'rules' => ['pattern' => '/^[A-Z0-9-]+$/']],
            'description' => ['label' => ['pl' => 'Opis', 'en' => 'Description'], 'type' => AttributeType::Text, 'localizable' => true],
            'description_html' => ['label' => ['pl' => 'Opis (rich text)', 'en' => 'Description (rich text)'], 'type' => AttributeType::Wysiwyg, 'localizable' => true, 'rules' => ['max_length' => 50_000]],
            'short_description' => ['label' => ['pl' => 'Krótki opis', 'en' => 'Short description'], 'type' => AttributeType::Text, 'localizable' => true, 'scopable' => true, 'rules' => ['max_length' => 280]],
            'brand' => ['label' => ['pl' => 'Marka', 'en' => 'Brand'], 'type' => AttributeType::Text, 'filterable' => true],
            'tags' => ['label' => ['pl' => 'Tagi', 'en' => 'Tags'], 'type' => AttributeType::Multiselect, 'filterable' => true, 'rules' => ['max_count' => 5], 'options' => [['new', ['pl' => 'Nowość', 'en' => 'New']], ['sale', ['pl' => 'Promocja', 'en' => 'Sale']], ['eco', ['pl' => 'Eko', 'en' => 'Eco']], ['premium', ['pl' => 'Premium', 'en' => 'Premium']]]],
            'price' => ['label' => ['pl' => 'Cena', 'en' => 'Price'], 'type' => AttributeType::Price, 'scopable' => true, 'filterable' => true, 'rules' => ['min_amount' => 0, 'currencies' => ['PLN', 'EUR', 'USD']]],
            'weight' => ['label' => ['pl' => 'Waga', 'en' => 'Weight'], 'type' => AttributeType::Metric, 'filterable' => true, 'rules' => ['units' => ['kg', 'g'], 'min' => 0]],
            'in_stock' => ['label' => ['pl' => 'Na stanie', 'en' => 'In stock'], 'type' => AttributeType::Boolean, 'filterable' => true],
            'release_date' => ['label' => ['pl' => 'Data premiery', 'en' => 'Release date'], 'type' => AttributeType::Date, 'filterable' => true],
            'eol_date' => ['label' => ['pl' => 'Koniec wsparcia', 'en' => 'End of life'], 'type' => AttributeType::Date],
            'main_image' => ['label' => ['pl' => 'Zdjęcie główne', 'en' => 'Main image'], 'type' => AttributeType::Asset],
            'ip_rating' => ['label' => ['pl' => 'Klasa szczelności (IP)', 'en' => 'IP rating'], 'type' => AttributeType::Select, 'options' => [['IP54', ['pl' => 'IP54', 'en' => 'IP54']], ['IP67', ['pl' => 'IP67', 'en' => 'IP67']], ['IP68', ['pl' => 'IP68', 'en' => 'IP68']]]],
            'vat_rate' => ['label' => ['pl' => 'Stawka VAT', 'en' => 'VAT rate'], 'type' => AttributeType::Select, 'options' => [['vat_23', ['pl' => '23%', 'en' => '23%'], null, true], ['vat_8', ['pl' => '8%', 'en' => '8%']], ['vat_0', ['pl' => '0%', 'en' => '0%']]]],
            'warranty_months' => ['label' => ['pl' => 'Gwarancja (msc)', 'en' => 'Warranty (months)'], 'type' => AttributeType::Number, 'rules' => ['min' => 0, 'max' => 120]],
            'voltage' => ['label' => ['pl' => 'Napięcie', 'en' => 'Voltage'], 'type' => AttributeType::Metric, 'rules' => ['units' => ['V'], 'min' => 0]],
            'power_w' => ['label' => ['pl' => 'Moc', 'en' => 'Power'], 'type' => AttributeType::Metric, 'rules' => ['units' => ['W'], 'min' => 0]],

            // ── Electronics-specific additions ──
            'ean' => ['label' => ['pl' => 'EAN', 'en' => 'EAN'], 'type' => AttributeType::Text, 'filterable' => true, 'rules' => ['pattern' => '/^\d{13}$/']],
            'screen_size' => ['label' => ['pl' => 'Przekątna ekranu', 'en' => 'Screen size'], 'type' => AttributeType::Metric, 'filterable' => true, 'rules' => ['units' => ['in', 'cm'], 'min' => 0]],
            'resolution' => ['label' => ['pl' => 'Rozdzielczość', 'en' => 'Resolution'], 'type' => AttributeType::Select, 'filterable' => true, 'options' => [['hd', ['pl' => 'HD (1366×768)', 'en' => 'HD (1366×768)']], ['fhd', ['pl' => 'Full HD (1920×1080)', 'en' => 'Full HD (1920×1080)']], ['qhd', ['pl' => 'QHD (2560×1440)', 'en' => 'QHD (2560×1440)']], ['uhd4k', ['pl' => '4K UHD (3840×2160)', 'en' => '4K UHD (3840×2160)']], ['uhd8k', ['pl' => '8K UHD (7680×4320)', 'en' => '8K UHD (7680×4320)']]]],
            'refresh_rate' => ['label' => ['pl' => 'Częstotliwość odświeżania (Hz)', 'en' => 'Refresh rate (Hz)'], 'type' => AttributeType::Number, 'filterable' => true, 'rules' => ['min' => 0, 'max' => 480]],
            'panel_type' => ['label' => ['pl' => 'Typ matrycy', 'en' => 'Panel type'], 'type' => AttributeType::Select, 'filterable' => true, 'options' => [['ips', ['pl' => 'IPS', 'en' => 'IPS'], '#0EA5E9'], ['va', ['pl' => 'VA', 'en' => 'VA'], '#64748B'], ['oled', ['pl' => 'OLED', 'en' => 'OLED'], '#A855F7'], ['amoled', ['pl' => 'AMOLED', 'en' => 'AMOLED'], '#EC4899'], ['miniled', ['pl' => 'Mini-LED', 'en' => 'Mini-LED'], '#F59E0B']]],
            'cpu' => ['label' => ['pl' => 'Procesor', 'en' => 'CPU'], 'type' => AttributeType::Text, 'filterable' => true],
            'ram' => ['label' => ['pl' => 'Pamięć RAM', 'en' => 'RAM'], 'type' => AttributeType::Select, 'filterable' => true, 'options' => [['4gb', ['pl' => '4 GB', 'en' => '4 GB']], ['8gb', ['pl' => '8 GB', 'en' => '8 GB']], ['16gb', ['pl' => '16 GB', 'en' => '16 GB']], ['32gb', ['pl' => '32 GB', 'en' => '32 GB']], ['64gb', ['pl' => '64 GB', 'en' => '64 GB']]]],
            'storage' => ['label' => ['pl' => 'Pamięć wbudowana', 'en' => 'Storage'], 'type' => AttributeType::Select, 'filterable' => true, 'options' => [['128gb', ['pl' => '128 GB', 'en' => '128 GB']], ['256gb', ['pl' => '256 GB', 'en' => '256 GB']], ['512gb', ['pl' => '512 GB', 'en' => '512 GB']], ['1tb', ['pl' => '1 TB', 'en' => '1 TB']], ['2tb', ['pl' => '2 TB', 'en' => '2 TB']]]],
            'os' => ['label' => ['pl' => 'System operacyjny', 'en' => 'Operating system'], 'type' => AttributeType::Select, 'filterable' => true, 'options' => [['android', ['pl' => 'Android', 'en' => 'Android']], ['ios', ['pl' => 'iOS / iPadOS', 'en' => 'iOS / iPadOS']], ['windows', ['pl' => 'Windows 11', 'en' => 'Windows 11']], ['macos', ['pl' => 'macOS', 'en' => 'macOS']], ['linux', ['pl' => 'Linux', 'en' => 'Linux']], ['none', ['pl' => 'Brak / dedykowany', 'en' => 'None / dedicated']]]],
            'battery_capacity' => ['label' => ['pl' => 'Pojemność baterii (mAh)', 'en' => 'Battery capacity (mAh)'], 'type' => AttributeType::Number, 'filterable' => true, 'rules' => ['min' => 0]],
            'camera_mp' => ['label' => ['pl' => 'Matryca aparatu (Mpix)', 'en' => 'Camera (MP)'], 'type' => AttributeType::Number, 'filterable' => true, 'rules' => ['min' => 0]],
            'connectivity' => ['label' => ['pl' => 'Łączność', 'en' => 'Connectivity'], 'type' => AttributeType::Multiselect, 'filterable' => true, 'options' => [['wifi', ['pl' => 'Wi-Fi', 'en' => 'Wi-Fi']], ['bt', ['pl' => 'Bluetooth', 'en' => 'Bluetooth']], ['nfc', ['pl' => 'NFC', 'en' => 'NFC']], ['g5', ['pl' => '5G', 'en' => '5G']], ['usb_c', ['pl' => 'USB-C', 'en' => 'USB-C']], ['hdmi', ['pl' => 'HDMI', 'en' => 'HDMI']], ['ethernet', ['pl' => 'Ethernet', 'en' => 'Ethernet']]]],
            'energy_class' => ['label' => ['pl' => 'Klasa energetyczna', 'en' => 'Energy class'], 'type' => AttributeType::Select, 'filterable' => true, 'options' => [['a', ['pl' => 'A', 'en' => 'A'], '#22C55E'], ['b', ['pl' => 'B', 'en' => 'B'], '#84CC16'], ['c', ['pl' => 'C', 'en' => 'C'], '#EAB308'], ['d', ['pl' => 'D', 'en' => 'D'], '#F97316'], ['e', ['pl' => 'E', 'en' => 'E'], '#EF4444']]],
            'capacity_l' => ['label' => ['pl' => 'Pojemność', 'en' => 'Capacity'], 'type' => AttributeType::Metric, 'rules' => ['units' => ['l', 'kg'], 'min' => 0]],
            'spin_speed' => ['label' => ['pl' => 'Prędkość wirowania (obr./min)', 'en' => 'Spin speed (rpm)'], 'type' => AttributeType::Number, 'rules' => ['min' => 0, 'max' => 2000]],
            'noise_level' => ['label' => ['pl' => 'Poziom hałasu (dB)', 'en' => 'Noise level (dB)'], 'type' => AttributeType::Number, 'rules' => ['min' => 0, 'max' => 120]],
            'sensor_type' => ['label' => ['pl' => 'Typ matrycy (foto)', 'en' => 'Sensor type'], 'type' => AttributeType::Select, 'options' => [['aps_c', ['pl' => 'APS-C', 'en' => 'APS-C']], ['full_frame', ['pl' => 'Pełna klatka', 'en' => 'Full frame']], ['micro43', ['pl' => 'Mikro 4/3', 'en' => 'Micro 4/3']]]],
            'optical_zoom' => ['label' => ['pl' => 'Zoom optyczny (×)', 'en' => 'Optical zoom (×)'], 'type' => AttributeType::Number, 'rules' => ['min' => 0, 'max' => 100]],
            'wifi_standard' => ['label' => ['pl' => 'Standard Wi-Fi', 'en' => 'Wi-Fi standard'], 'type' => AttributeType::Select, 'filterable' => true, 'options' => [['wifi5', ['pl' => 'Wi-Fi 5 (802.11ac)', 'en' => 'Wi-Fi 5 (802.11ac)']], ['wifi6', ['pl' => 'Wi-Fi 6 (802.11ax)', 'en' => 'Wi-Fi 6 (802.11ax)']], ['wifi6e', ['pl' => 'Wi-Fi 6E', 'en' => 'Wi-Fi 6E']], ['wifi7', ['pl' => 'Wi-Fi 7', 'en' => 'Wi-Fi 7']]]],
            'lan_ports' => ['label' => ['pl' => 'Porty LAN', 'en' => 'LAN ports'], 'type' => AttributeType::Number, 'rules' => ['min' => 0, 'max' => 48]],

            // ── Service attributes ──
            'service_type' => ['label' => ['pl' => 'Tryb realizacji', 'en' => 'Delivery mode'], 'type' => AttributeType::Select, 'filterable' => true, 'options' => [['onsite', ['pl' => 'U klienta', 'en' => 'On-site']], ['remote', ['pl' => 'Zdalnie', 'en' => 'Remote']], ['workshop', ['pl' => 'W serwisie', 'en' => 'Workshop']]]],
            'service_duration' => ['label' => ['pl' => 'Czas realizacji', 'en' => 'Duration'], 'type' => AttributeType::Metric, 'rules' => ['units' => ['h', 'min'], 'min' => 0]],
            'sla_days' => ['label' => ['pl' => 'SLA (dni)', 'en' => 'SLA (days)'], 'type' => AttributeType::Number, 'rules' => ['min' => 0, 'max' => 60]],
            'includes_parts' => ['label' => ['pl' => 'Części w cenie', 'en' => 'Parts included'], 'type' => AttributeType::Boolean],
        ];
    }
}
