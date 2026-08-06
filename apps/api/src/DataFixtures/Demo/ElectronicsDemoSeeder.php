<?php

declare(strict_types=1);

namespace App\DataFixtures\Demo;

use App\Asset\Contracts\AssetIngestorInterface;
use App\Catalog\Application\BulkContext;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\AttributeOption;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\CategoryAttributeGroup;
use App\Catalog\Domain\Entity\ObjectCategory;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Provenance;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\MenuConfigurationRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Catalog\Domain\Value\MenuItemRecord;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

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

    /** With --keep-existing the purge is skipped: reuse rows whose codes already exist. */
    private bool $reuseExisting = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly BulkContext $bulkContext,
        private readonly ObjectTypeRepositoryInterface $objectTypeRepository,
        private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly CatalogObjectRepositoryInterface $catalogObjects,
        private readonly ElectronicsDemoModelingSeeder $modeling,
        private readonly MenuConfigurationRepositoryInterface $menuConfigurations,
        private readonly AssetIngestorInterface $assetIngestor,
    ) {
    }

    /**
     * @param array<string, Uuid>        $channelIds channel code => id (baselinker / shopify / magento)
     * @param Closure(string): void|null $progress
     */
    public function seed(Tenant $tenant, array $channelIds, int $productCount = 1000, ?Closure $progress = null, bool $reuseExisting = false): void
    {
        $this->reuseExisting = $reuseExisting;
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

            $groups = $this->modeling->ensureGroups($tenant, $attributes, $productType, $serviceType);
            $this->modeling->ensureJunctions($productType, $serviceType, $attributes);
            $this->em->flush();
            $notify(\sprintf('Attribute groups ready (%d).', \count($groups)));

            $categories = $this->seedCategories($categoryType, $productType, $serviceType, $attributes);
            $this->em->flush();
            $this->pinCategoryGroups($categories, $productType, $groups);
            $this->ensureMenuEntry($tenant, $serviceType);
            $this->em->flush();
            $notify(\sprintf('Categories ready (%d product-tree + %d service-tree).', \count(ElectronicsDemoData::PRODUCT_CATEGORIES), \count(ElectronicsDemoData::SERVICE_CATEGORIES)));

            foreach ($categories as $code => $category) {
                $this->categoryIds[$code] = $category->getId()->toRfc4122();
            }
            foreach ($attributes as $code => $attribute) {
                $this->attributeIds[$code] = $attribute->getId()->toRfc4122();
            }

            $this->seedServices($serviceType, $attributes, $categories, $channelIds);
            $this->em->flush();
            $notify(\sprintf('Services seeded (%d).', \count(ElectronicsDemoData::SERVICES)));

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
        foreach (ElectronicsDemoData::attributeDefinitions() as $code => $def) {
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

    // ─── Categories ──────────────────────────────────────────────────

    /**
     * @param array<string, Attribute> $attributes
     *
     * @return array<string, CatalogObject>
     */
    private function seedCategories(ObjectType $categoryType, ObjectType $productType, ObjectType $serviceType, array $attributes): array
    {
        $categories = [];

        foreach (ElectronicsDemoData::PRODUCT_CATEGORIES as $code => $def) {
            $existing = $this->findExisting('CAT-'.strtoupper($code), ObjectKind::Category);
            if (null !== $existing) {
                $categories[$code] = $existing;
                $this->categoryImageLabels[$code] = $def['label']['en'];
                continue;
            }
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

        foreach (ElectronicsDemoData::SERVICE_CATEGORIES as $code => $def) {
            $existing = $this->findExisting('CAT-'.strtoupper($code), ObjectKind::Category);
            if (null !== $existing) {
                $categories[$code] = $existing;
                continue;
            }
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

        $pinRepo = $this->em->getRepository(CategoryAttributeGroup::class);
        foreach ($pins as $groupCode => $categoryCodes) {
            foreach ($categoryCodes as $pinPosition => $categoryCode) {
                $categoryId = $categories[$categoryCode]->getId();
                if (null !== $pinRepo->findOneBy(['categoryObjectId' => $categoryId, 'attributeGroup' => $groups[$groupCode], 'targetObjectType' => $productType])) {
                    continue;
                }
                $this->em->persist(new CategoryAttributeGroup($categoryId, $productType, $groups[$groupCode], $pinPosition));
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
        foreach (ElectronicsDemoData::SERVICES as $def) {
            ++$index;
            $sku = \sprintf('SRV-%03d', $index);
            if (null !== $this->findExisting($sku, ObjectKind::Custom)) {
                continue;
            }
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
        foreach (ElectronicsDemoData::PRODUCT_CATEGORIES as $code => $def) {
            $totalWeight += $def['count'];
        }
        foreach (ElectronicsDemoData::PRODUCT_CATEGORIES as $code => $def) {
            if (0 === $def['count']) {
                continue;
            }
            $share = (int) round($def['count'] / $totalWeight * $productCount);
            for ($i = 0; $i < $share && \count($plan) < $productCount; ++$i) {
                $plan[] = $code;
            }
        }
        $fillerCodes = array_keys(array_filter(ElectronicsDemoData::PRODUCT_CATEGORIES, static fn (array $def): bool => $def['count'] > 0));
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
        $def = ElectronicsDemoData::PRODUCT_CATEGORIES[$categoryCode];
        $sku = \sprintf('ELEC-%04d', $i);
        if (null !== $this->findExisting($sku, ObjectKind::Product)) {
            return;
        }
        $models = $def['models'];
        if ([] === $models) {
            throw new RuntimeException(\sprintf('Category "%s" carries products but has no model pool.', $categoryCode));
        }
        $model = self::pick($models);
        $brand = explode(' ', $model)[0];
        [$colorPl, $colorEn] = ElectronicsDemoData::COLOR_WORDS[mt_rand(0, \count(ElectronicsDemoData::COLOR_WORDS) - 1)];

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
            $extras['cpu'] = ['value' => self::pick(['Quorvex Q8 Gen 3', 'Aurion M3', 'Corvex Ultra 7', 'Vexar R7 8840U', 'Nexcore D9300', 'Corvex U5-1340'])];
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

        $descPl = \sprintf('%s marki %s z kategorii %s. Solidne wykonanie, nowoczesne funkcje i %d miesięcy gwarancji. Indeks katalogowy %s.', $namePl, $brand, ElectronicsDemoData::PRODUCT_CATEGORIES[$categoryCode]['label']['pl'], 24, $sku);
        $descEn = \sprintf('%s by %s from the %s category. Solid build, modern features and a 24-month warranty. Catalog index %s.', $nameEn, $brand, ElectronicsDemoData::PRODUCT_CATEGORIES[$categoryCode]['label']['en'], $sku);

        $payloads = [
            'sku' => ['value' => $sku],
            'ean' => ['value' => \sprintf('590%010d', ($i * 7919) % 10_000_000_000)],
            'name' => ['value' => $namePl],
            'description' => ['value' => $descPl],
            'short_description' => ['value' => \sprintf('%s — %s', $namePl, ElectronicsDemoData::PRODUCT_CATEGORIES[$categoryCode]['label']['pl'])],
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

    /**
     * Render a placeholder JPEG for the product and push it through the
     * real Asset pipeline. Returns the Asset id for `main_image`.
     */
    private function ingestProductImage(string $sku, string $title, string $categoryLabel, int $hue): string
    {
        $tmp = ElectronicsDemoImageFactory::renderJpeg($sku, $title, $categoryLabel, $hue);

        try {
            $result = $this->assetIngestor->ingest($tmp, strtolower($sku).'.jpg');

            return $result->assetId->toRfc4122();
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Existing-row lookup used only in `--keep-existing` mode; the default
     * purge path skips the per-code query entirely.
     */
    private function findExisting(string $code, ObjectKind $kind): ?CatalogObject
    {
        if (!$this->reuseExisting) {
            return null;
        }
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            return null;
        }

        return $this->catalogObjects->findByCode($code, $kind, $tenant);
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
}
