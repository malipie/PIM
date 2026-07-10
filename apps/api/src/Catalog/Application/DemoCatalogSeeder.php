<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Asset\Domain\Entity\Asset;
use App\Asset\Domain\Entity\AssetVariant;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeOption;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Provenance;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Idempotent demo dataset seeder for ticket #40 (0.3.10).
 *
 * Builds a representative catalog inside one tenant: ~20 attributes
 * covering all 10 AttributeType cases, 100 products, 5 categories, and 10
 * assets.
 *
 * Asset and Category are closed system kinds (amends ADR-009): they carry
 * NO attached attributes — only an intrinsic `name` (display label, written
 * straight into `attributes_indexed` without an `object_type_attributes`
 * junction). All attribute attachments live on Product.
 *
 * Bulk path: {@see BulkContext} flips ON to bypass the synchronous
 * `AttributesIndexedSyncListener` (#38) — `attributes_indexed` is built
 * inline alongside the canonical `object_values` rows so the cache
 * matches the source of truth without forcing 1000+ listener walks.
 *
 * Idempotent: re-runs are no-ops once a CatalogObject with code
 * `DEMO-100` exists (the last product seeded — its presence proves the
 * full set went through). Foundry / fixtures may run this many times
 * during a `pim:db:reset`; the early-exit keeps it fast.
 */
final readonly class DemoCatalogSeeder
{
    private const string SENTINEL_PRODUCT_CODE = 'DEMO-100';
    private const int PRODUCT_COUNT = 100;

    public function __construct(
        private EntityManagerInterface $em,
        private TenantContext $tenantContext,
        private BulkContext $bulkContext,
        private ObjectTypeRepositoryInterface $objectTypeRepository,
        private AttributeRepositoryInterface $attributeRepository,
        private CatalogObjectRepositoryInterface $catalogObjectRepository,
    ) {
    }

    /**
     * @param ?Uuid $demoChannelId when provided, a handful of demo products get
     *                             per-channel `price` overrides for this channel
     *                             (#1259 — makes the channel picker show a real
     *                             difference instead of a hardcoded mock)
     */
    public function seed(Tenant $tenant, ?Uuid $demoChannelId = null): void
    {
        $previous = $this->tenantContext->get();
        $this->tenantContext->set($tenant);
        $this->bulkContext->setBulk(true);

        try {
            if (null !== $this->catalogObjectRepository->findByCode(self::SENTINEL_PRODUCT_CODE, ObjectKind::Product, $tenant)) {
                return;
            }

            $productType = $this->objectTypeRepository->findBuiltInByKind(ObjectKind::Product, $tenant);
            $categoryType = $this->objectTypeRepository->findBuiltInByKind(ObjectKind::Category, $tenant);
            $assetType = $this->objectTypeRepository->findBuiltInByKind(ObjectKind::Asset, $tenant);
            \assert(null !== $productType && null !== $categoryType && null !== $assetType, 'Built-in ObjectTypes must be seeded before DemoCatalogSeeder runs.');

            $attributes = $this->seedAttributes($tenant);
            $this->seedJunctions($productType, $attributes);
            $productType->assignLabelAttribute($attributes['name']);
            $productType->assignImageAttribute($attributes['main_image']);
            $productType->updateCompletenessRules(['required' => ['sku', 'name', 'description', 'price']]);
            // Asset / Category are closed system kinds: `name` is the display
            // label (FK pointer, not a junction) — no attached attributes.
            $categoryType->assignLabelAttribute($attributes['name']);
            $categoryType->updateCompletenessRules(['required' => ['name']]);
            $assetType->assignLabelAttribute($attributes['name']);
            $this->em->flush();

            $categories = $this->seedCategories($categoryType, $productType, $attributes);
            $this->em->flush();

            $assets = $this->seedAssets($assetType, $tenant, $attributes);
            $this->em->flush();

            $this->seedProducts($productType, $categories, $assets, $attributes, $demoChannelId);
            $this->em->flush();
        } finally {
            $this->bulkContext->setBulk(false);
            if (null === $previous) {
                $this->tenantContext->clear();
            } else {
                $this->tenantContext->set($previous);
            }
        }
    }

    /**
     * @return array<string, Attribute>
     */
    private function seedAttributes(Tenant $tenant): array
    {
        $defs = self::attributeDefinitions();
        $attributes = [];
        $position = 0;
        foreach ($defs as $code => $def) {
            $existing = $this->attributeRepository->findByCode($code, $tenant);
            if (null !== $existing) {
                $attributes[$code] = $existing;
                continue;
            }
            $attribute = new Attribute($code, $def['label'], $def['type']);
            $attribute->changeRequired($def['required'] ?? false);
            $attribute->changeLocalizable($def['localizable'] ?? false);
            $attribute->changeScopable($def['scopable'] ?? false);
            // #1354 — flag demo attributes as filterable so they appear in
            // the advanced filter panel (the picker now shows ONLY
            // `is_filterable=true` attributes) AND actually resolve in
            // Meilisearch (the index settings union `is_filterable` codes
            // into `filterableAttributes`). Without this the strict panel
            // would render an empty attribute picker on the demo data.
            $attribute->changeFilterable($def['filterable'] ?? false);
            $attribute->updateValidationRules($def['rules'] ?? []);
            $attribute->reorder($position++);
            $this->em->persist($attribute);
            $attributes[$code] = $attribute;

            foreach ($def['options'] ?? [] as $optPosition => $optDef) {
                // Backward-compat: 2-element [code, label] still supported,
                // 5-element [code, label, color, default, deprecated] is the
                // VIEW-02 (#374) extended shape used by the `color` attribute.
                $optCode = $optDef[0];
                $optLabel = $optDef[1];
                $optColor = $optDef[2] ?? null;
                $optDefault = $optDef[3] ?? false;
                $optDeprecated = $optDef[4] ?? false;
                $option = new AttributeOption(
                    attribute: $attribute,
                    code: $optCode,
                    label: $optLabel,
                    position: $optPosition,
                    color: $optColor,
                    isDefault: $optDefault,
                    isDeprecated: $optDeprecated,
                );
                $this->em->persist($option);
            }
        }
        $this->em->flush();

        return $attributes;
    }

    /**
     * @param array<string, Attribute> $attributes
     */
    private function seedJunctions(ObjectType $product, array $attributes): void
    {
        // Only Product is attribute-modelable. Asset / Category are closed
        // system kinds (amends ADR-009) — zero `object_type_attributes` rows.
        $productAttrs = ['name', 'sku', 'description', 'description_html', 'short_description', 'brand', 'color', 'size', 'tags', 'price', 'weight', 'height', 'in_stock', 'release_date', 'main_image', 'related_to'];

        $sort = 0;
        foreach ($productAttrs as $code) {
            $required = \in_array($code, ['sku', 'name', 'description', 'price'], true);
            $junction = new ObjectTypeAttribute($product, $attributes[$code], $required, $sort++);
            $this->em->persist($junction);
        }
    }

    /**
     * @param array<string, Attribute> $attributes
     *
     * @return list<CatalogObject>
     */
    private function seedCategories(ObjectType $type, ObjectType $productType, array $attributes): array
    {
        $defs = [
            ['CAT-FOOTWEAR', 'footwear',  'Obuwie',         'Buty męskie i damskie'],
            ['CAT-APPAREL',  'apparel',   'Odzież',         'Ubrania i akcesoria'],
            ['CAT-OUTDOOR',  'outdoor',   'Outdoor',        'Sprzęt turystyczny'],
            ['CAT-RUNNING',  'running',   'Bieganie',       'Sprzęt biegowy'],
            ['CAT-SALE',     'sale',      'Wyprzedaż',      'Promocje sezonowe'],
        ];

        $categories = [];
        foreach ($defs as [$code, $path, $name]) {
            $category = new CatalogObject($type, $code);
            // ADR-015 — demo categories live in the Product tree.
            $category->scopeCategoryTo($productType);
            $category->forceStatus(CatalogObject::STATUS_PUBLISHED);
            $category->attachToPath($path);

            // Closed system kind — only the intrinsic `name` (display label).
            // No seo_title / seo_description / main_image attribute values.
            $namePayload = ['value' => $name];
            $value = new ObjectValue($category, $attributes['name'], $namePayload, Provenance::Import);
            $this->em->persist($value);
            $category->updateAttributeIndex(['name' => $namePayload]);
            $category->recordCompleteness(['global' => 100]);
            $this->em->persist($category);
            $categories[] = $category;
        }

        return $categories;
    }

    /**
     * @param array<string, Attribute> $attributes
     *
     * @return list<Asset>
     */
    private function seedAssets(ObjectType $type, Tenant $tenant, array $attributes): array
    {
        $assets = [];
        for ($i = 1; $i <= 10; ++$i) {
            $code = \sprintf('ASSET-%03d', $i);
            $catalogAsset = new CatalogObject($type, $code);
            $catalogAsset->forceStatus(CatalogObject::STATUS_PUBLISHED);

            $name = \sprintf('Demo image %d', $i);

            // Closed system kind — only the intrinsic `name` (display label).
            // No alt_text / caption attribute values.
            $value = new ObjectValue($catalogAsset, $attributes['name'], ['value' => $name], Provenance::Import);
            $this->em->persist($value);

            $catalogAsset->updateAttributeIndex([
                'name' => ['value' => $name],
            ]);
            $catalogAsset->recordCompleteness(['global' => 100]);
            $this->em->persist($catalogAsset);

            $storagePath = \sprintf(
                '%s/demo/%s.jpg',
                $tenant->getId()->toRfc4122(),
                $code,
            );
            $asset = new Asset(
                code: $code,
                originalFilename: \sprintf('%s.jpg', $code),
                mimeType: 'image/jpeg',
                size: 1024 * 12,
                storagePath: $storagePath,
            );
            $asset->linkToObject($catalogAsset->getId());
            $variant = new AssetVariant($asset, AssetVariant::CODE_ORIGINAL, $storagePath, 'image/jpeg', 1024 * 12);
            $asset->addVariant($variant);
            $this->em->persist($asset);
            $this->em->persist($variant);
            $assets[] = $asset;
        }

        return $assets;
    }

    /**
     * @param array<string, Attribute> $attributes
     * @param list<CatalogObject>      $categories
     * @param list<Asset>              $assets
     */
    private function seedProducts(ObjectType $type, array $categories, array $assets, array $attributes, ?Uuid $demoChannelId = null): void
    {
        $brands = ['Acme', 'Globex', 'Soylent', 'Initech', 'Hooli', 'Festo', 'Bosch'];
        $colors = ['red', 'green', 'blue', 'black', 'white'];
        $sizes = ['XS', 'S', 'M', 'L', 'XL'];
        $tagSets = [['new'], ['sale', 'eco'], ['premium'], ['new', 'premium'], ['sale']];
        $currencies = ['PLN', 'EUR', 'USD'];
        $units = ['kg', 'g', 'cm'];

        for ($i = 1; $i <= self::PRODUCT_COUNT; ++$i) {
            $sku = \sprintf('DEMO-%03d', $i);
            $product = new CatalogObject($type, $sku);
            $product->forceStatus(0 === $i % 11 ? CatalogObject::STATUS_DRAFT : CatalogObject::STATUS_PUBLISHED);

            $brand = $brands[$i % \count($brands)];
            $color = $colors[$i % \count($colors)];
            $size = $sizes[$i % \count($sizes)];
            $tags = $tagSets[$i % \count($tagSets)];
            $currency = $currencies[$i % \count($currencies)];
            $unit = $units[$i % \count($units)];
            $assetRow = $assets[$i % \count($assets)];
            $category = $categories[$i % \count($categories)];

            $payloads = [
                'sku' => ['value' => $sku],
                'name' => ['value' => \sprintf('Demo product %03d', $i)],
                'description' => ['value' => \sprintf('Long-form description for SKU %s, brand %s.', $sku, $brand)],
                'short_description' => ['value' => \sprintf('Short tagline for %s.', $sku)],
                'brand' => ['value' => $brand],
                'color' => ['option_code' => $color],
                'size' => ['option_code' => $size],
                'tags' => ['option_codes' => $tags],
                'price' => ['amount' => 19.99 + $i, 'currency' => $currency],
                'weight' => ['value' => round(0.2 + ($i * 0.1), 2), 'unit' => $unit],
                'height' => ['value' => 10 + $i],
                'in_stock' => ['value' => 0 !== $i % 7],
                'release_date' => ['value' => \sprintf('2026-%02d-15', 1 + ($i % 12))],
                'main_image' => ['asset_id' => $assetRow->getId()->toRfc4122()],
                'related_to' => ['object_id' => $category->getId()->toRfc4122()],
            ];

            $indexed = [];
            foreach ($payloads as $code => $payload) {
                $value = new ObjectValue($product, $attributes[$code], $payload, Provenance::Import);
                $this->em->persist($value);
                $indexed[$code] = $payload;
            }
            $product->updateAttributeIndex($indexed);
            $product->recordCompleteness(['global' => 100]);
            $this->em->persist($product);

            // #1259 — give the first few products real per-locale + per-channel
            // overrides so the product card's locale/channel pickers show a
            // visible difference (not just a fallback to the global value).
            // Only a small slice (i <= 5) keeps the demo light while still
            // proving the overlay works end-to-end after a DB reset.
            if ($i <= 5) {
                // Per-locale (EN) overrides — locale switch shows different text.
                $this->em->persist(new ObjectValue(
                    $product,
                    $attributes['name'],
                    ['value' => \sprintf('Demo product %03d (EN)', $i)],
                    Provenance::Import,
                    null,
                    'en',
                ));
                $this->em->persist(new ObjectValue(
                    $product,
                    $attributes['description'],
                    ['value' => \sprintf('English description for SKU %s, brand %s.', $sku, $brand)],
                    Provenance::Import,
                    null,
                    'en',
                ));
                $this->em->persist(new ObjectValue(
                    $product,
                    $attributes['short_description'],
                    ['value' => \sprintf('Short EN tagline for %s.', $sku)],
                    Provenance::Import,
                    null,
                    'en',
                ));

                if (null !== $demoChannelId) {
                    // Allegro charges a different price — per-channel override.
                    $this->em->persist(new ObjectValue(
                        $product,
                        $attributes['price'],
                        ['amount' => 24.99 + $i, 'currency' => $currency],
                        Provenance::Import,
                        $demoChannelId,
                        null,
                    ));
                    // short_description is localizable AND scopable — give it an
                    // Allegro-specific tagline so the row shows both a PL chip and
                    // an Allegro chip with genuinely different content.
                    $this->em->persist(new ObjectValue(
                        $product,
                        $attributes['short_description'],
                        ['value' => \sprintf('Allegro tagline for %s.', $sku)],
                        Provenance::Import,
                        $demoChannelId,
                        null,
                    ));
                }
            }
        }
    }

    /**
     * @return array<string, array{label: array<string, string>, type: AttributeType, required?: bool, localizable?: bool, scopable?: bool, filterable?: bool, rules?: array<string, mixed>, options?: list<array{0: string, 1: array<string, string>, 2?: string|null, 3?: bool, 4?: bool}>}>
     */
    private static function attributeDefinitions(): array
    {
        return [
            'name' => [
                'label' => ['pl' => 'Nazwa', 'en' => 'Name'],
                'type' => AttributeType::Text,
                'required' => true,
                'localizable' => true,
                'rules' => ['max_length' => 255],
            ],
            'sku' => [
                'label' => ['pl' => 'SKU', 'en' => 'SKU'],
                'type' => AttributeType::Text,
                'required' => true,
                'rules' => ['pattern' => '/^[A-Z0-9-]+$/'],
            ],
            'description' => [
                'label' => ['pl' => 'Opis', 'en' => 'Description'],
                'type' => AttributeType::Text,
                'localizable' => true,
            ],
            'description_html' => [
                'label' => ['pl' => 'Opis (rich text)', 'en' => 'Description (rich text)'],
                'type' => AttributeType::Wysiwyg,
                'localizable' => true,
                'rules' => ['max_length' => 50_000],
            ],
            'short_description' => [
                'label' => ['pl' => 'Krótki opis', 'en' => 'Short description'],
                'type' => AttributeType::Text,
                // #1259 — both localizable AND scopable: marketing copy differs
                // per language and per channel. On the product card this row
                // shows a PL chip + an Allegro chip side by side once a channel
                // is selected — the exact "channel next to locale" affordance.
                'localizable' => true,
                'scopable' => true,
                'rules' => ['max_length' => 280],
            ],
            'brand' => [
                'label' => ['pl' => 'Marka', 'en' => 'Brand'],
                'type' => AttributeType::Text,
                'filterable' => true,
            ],
            'color' => [
                'label' => ['pl' => 'Kolor', 'en' => 'Color'],
                'type' => AttributeType::Select,
                // VIEW-02 (#374) — options carry hex colors so the FE Allowed
                // Values editor swatch dot renders next to each label, and
                // `red` is the default option (one default per attribute,
                // partial unique index on attribute_options).
                'filterable' => true,
                'options' => [
                    ['red', ['pl' => 'Czerwony', 'en' => 'Red'], '#EF4444', true, false],
                    ['green', ['pl' => 'Zielony', 'en' => 'Green'], '#10B981', false, false],
                    ['blue', ['pl' => 'Niebieski', 'en' => 'Blue'], '#3B82F6', false, false],
                    ['black', ['pl' => 'Czarny', 'en' => 'Black'], '#18181B', false, false],
                    ['white', ['pl' => 'Biały', 'en' => 'White'], '#F4F4F5', false, false],
                ],
            ],
            'size' => [
                'label' => ['pl' => 'Rozmiar', 'en' => 'Size'],
                'type' => AttributeType::Select,
                'filterable' => true,
                'options' => [
                    ['XS', ['pl' => 'XS', 'en' => 'XS']],
                    ['S', ['pl' => 'S', 'en' => 'S']],
                    ['M', ['pl' => 'M', 'en' => 'M']],
                    ['L', ['pl' => 'L', 'en' => 'L']],
                    ['XL', ['pl' => 'XL', 'en' => 'XL']],
                ],
            ],
            'tags' => [
                'label' => ['pl' => 'Tagi', 'en' => 'Tags'],
                'type' => AttributeType::Multiselect,
                'filterable' => true,
                'rules' => ['max_count' => 5],
                'options' => [
                    ['new', ['pl' => 'Nowość', 'en' => 'New']],
                    ['sale', ['pl' => 'Promocja', 'en' => 'Sale']],
                    ['eco', ['pl' => 'Eko', 'en' => 'Eco']],
                    ['premium', ['pl' => 'Premium', 'en' => 'Premium']],
                ],
            ],
            'price' => [
                'label' => ['pl' => 'Cena', 'en' => 'Price'],
                'type' => AttributeType::Price,
                // #1259 — scopable so the demo carries per-channel price overrides
                // (Allegro charges a different amount); makes the channel picker
                // on the product card show a real difference, not a mock.
                'scopable' => true,
                'filterable' => true,
                'rules' => ['min_amount' => 0, 'currencies' => ['PLN', 'EUR', 'USD']],
            ],
            'weight' => [
                'label' => ['pl' => 'Waga', 'en' => 'Weight'],
                'type' => AttributeType::Metric,
                'filterable' => true,
                'rules' => ['units' => ['kg', 'g', 'cm'], 'min' => 0],
            ],
            'height' => [
                'label' => ['pl' => 'Wysokość', 'en' => 'Height'],
                'type' => AttributeType::Number,
                'filterable' => true,
                'rules' => ['min' => 0],
            ],
            'in_stock' => [
                'label' => ['pl' => 'Na stanie', 'en' => 'In stock'],
                'type' => AttributeType::Boolean,
                'filterable' => true,
            ],
            'release_date' => [
                'label' => ['pl' => 'Data premiery', 'en' => 'Release date'],
                'type' => AttributeType::Date,
                'filterable' => true,
            ],
            'main_image' => [
                'label' => ['pl' => 'Zdjęcie główne', 'en' => 'Main image'],
                'type' => AttributeType::Asset,
            ],
            'related_to' => [
                'label' => ['pl' => 'Powiązane', 'en' => 'Related'],
                'type' => AttributeType::Relation,
            ],
            // VIEW-02 (#374) — pixel-perfect attribute fixtures matching the
            // ATTRIBUTES list from `attributes.jsx` mockup: IP rating with 7
            // hex colors, VAT rate with 5 options + Polish default, currency
            // with 5 options, plus a few standalone numeric/date attributes
            // operator can show off in smoke. Position is implicit by array
            // order; the FE list reads it from the GET response.
            'ip_rating' => [
                'label' => ['pl' => 'Klasa szczelności (IP)', 'en' => 'IP rating'],
                'type' => AttributeType::Select,
                'options' => [
                    ['IP20', ['pl' => 'IP20', 'en' => 'IP20'], '#94A3B8', false, false],
                    ['IP44', ['pl' => 'IP44', 'en' => 'IP44'], '#0EA5E9', false, false],
                    ['IP54', ['pl' => 'IP54', 'en' => 'IP54'], '#10B981', true, false],
                    ['IP55', ['pl' => 'IP55', 'en' => 'IP55'], '#22C55E', false, false],
                    ['IP65', ['pl' => 'IP65', 'en' => 'IP65'], '#F59E0B', false, false],
                    ['IP67', ['pl' => 'IP67', 'en' => 'IP67'], '#EF4444', false, false],
                    ['IP68', ['pl' => 'IP68', 'en' => 'IP68'], '#A855F7', false, false],
                ],
            ],
            'vat_rate' => [
                'label' => ['pl' => 'Stawka VAT', 'en' => 'VAT rate'],
                'type' => AttributeType::Select,
                'options' => [
                    ['vat_23', ['pl' => '23%', 'en' => '23%'], null, true, false],
                    ['vat_8', ['pl' => '8%', 'en' => '8%'], null, false, false],
                    ['vat_5', ['pl' => '5%', 'en' => '5%'], null, false, false],
                    ['vat_0', ['pl' => '0%', 'en' => '0%'], null, false, false],
                    ['vat_zw', ['pl' => 'ZW (zwolnione)', 'en' => 'Exempt'], null, false, true],
                ],
            ],
            'currency_code' => [
                'label' => ['pl' => 'Waluta', 'en' => 'Currency'],
                'type' => AttributeType::Select,
                'options' => [
                    ['PLN', ['pl' => 'PLN · złoty', 'en' => 'PLN · Polish złoty'], null, true, false],
                    ['EUR', ['pl' => 'EUR · euro', 'en' => 'EUR · euro'], null, false, false],
                    ['USD', ['pl' => 'USD · dolar', 'en' => 'USD · US dollar'], null, false, false],
                    ['GBP', ['pl' => 'GBP · funt', 'en' => 'GBP · pound sterling'], null, false, false],
                    ['CHF', ['pl' => 'CHF · frank', 'en' => 'CHF · Swiss franc'], null, false, false],
                ],
            ],
            'warranty_months' => [
                'label' => ['pl' => 'Gwarancja (msc)', 'en' => 'Warranty (months)'],
                'type' => AttributeType::Number,
                'rules' => ['min' => 0, 'max' => 120],
            ],
            'voltage' => [
                'label' => ['pl' => 'Napięcie', 'en' => 'Voltage'],
                'type' => AttributeType::Metric,
                'rules' => ['units' => ['V'], 'min' => 0],
            ],
            'power_w' => [
                'label' => ['pl' => 'Moc', 'en' => 'Power'],
                'type' => AttributeType::Metric,
                'rules' => ['units' => ['W'], 'min' => 0],
            ],
            'requires_referral' => [
                'label' => ['pl' => 'Wymaga skierowania', 'en' => 'Requires referral'],
                'type' => AttributeType::Boolean,
            ],
            'eol_date' => [
                'label' => ['pl' => 'Koniec wsparcia', 'en' => 'End of life'],
                'type' => AttributeType::Date,
            ],
        ];
    }
}
