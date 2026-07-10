<?php

declare(strict_types=1);

namespace App\Tests\Api\Import;

use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeOption;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectCategory;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Provenance;
use App\Export\Application\Sync\SyncExportRunner;
use App\Export\Domain\Entity\ExportSession;
use App\Export\Domain\Enum\ExportEntityType;
use App\Export\Domain\Enum\ExportFormat;
use App\Export\Domain\Enum\ExportSource;
use App\Export\Domain\Enum\ExportTargetScope;
use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Enum\ImportSessionStatus;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;
use const PATHINFO_EXTENSION;

/**
 * IMP2-1.5 (#1467, ADR-0019) — GOLDEN ROUND-TRIP v0: the REAL exporter
 * produces a CSV which the REAL import engine consumes in UPSERT mode;
 * the test asserts envelope equality in the database afterwards.
 *
 * This replaces the dry-run-only ImportRoundTripApiTest (#1130) which
 * never ran the exporter and never persisted — format drift between
 * ValueSerializer and the import parsers is now guarded by CI.
 *
 * v0 matrix: every value-carrying AttributeType (global + one non-primary-locale (en; primary-locale columns normalise to the global row like the admin path — #1146 covered in IMP2-1.6)
 * column), single category assignment, plus the modified-cell scenario
 * (export → edit → re-import updates exactly one value). IMP2-1.6 adds the
 * channel-only and combined locale+channel scopes (gr_chan); variants,
 * relations and multi-categories extend this in IMP2-1.10 after their
 * engine tickets (1.7–1.8) land.
 *
 * Normalisation rules v1 (ADR-0019): numeric string ≡ number for
 * number/price.amount/metric.value; everything else compares strictly.
 */
final class GoldenRoundTripApiTest extends CatalogApiTestCase
{
    /**
     * type => [seeded envelope, csv column key].
     *
     * @var array<string, array{0: AttributeType, 1: array<string, mixed>}>
     */
    private const array MATRIX = [
        'gr_text' => [AttributeType::Text, ['value' => 'Stalowy uchwyt M8']],
        'gr_textarea' => [AttributeType::Textarea, ['value' => "Linia 1\nLinia 2"]],
        'gr_wysiwyg' => [AttributeType::Wysiwyg, ['value' => '<p>Opis <b>HTML</b></p>']],
        'gr_number' => [AttributeType::Number, ['value' => 12.5]],
        'gr_date' => [AttributeType::Date, ['value' => '2026-03-15']],
        'gr_datetime' => [AttributeType::Datetime, ['value' => '2026-03-15T10:30:00+00:00']],
        'gr_boolean' => [AttributeType::Boolean, ['value' => true]],
        'gr_color' => [AttributeType::Color, ['value' => '#ff8800']],
        'gr_email' => [AttributeType::Email, ['value' => 'kontakt@example.com']],
        'gr_identifier' => [AttributeType::Identifier, ['value' => '5901234123457']],
        'gr_select' => [AttributeType::Select, ['option_code' => 'red']],
        'gr_multiselect' => [AttributeType::Multiselect, ['option_codes' => ['new', 'sale']]],
        'gr_price' => [AttributeType::Price, ['amount' => 249.99, 'currency' => 'PLN']],
        'gr_metric' => [AttributeType::Metric, ['value' => 0.75, 'unit' => 'kg']],
    ];

    /**
     * IMP2-1.10 — the full matrix rides BOTH serialisation formats through the
     * real export → import engine. Same seeding, same envelope-equality
     * contract; only the writer/reader (CSV vs PhpSpreadsheet xlsx) differ.
     *
     * @return iterable<string, array{ExportFormat}>
     */
    public static function formats(): iterable
    {
        yield 'csv' => [ExportFormat::Csv];
        yield 'xlsx' => [ExportFormat::Xlsx];
    }

    #[Test]
    #[DataProvider('formats')]
    public function exportedFileReimportsWithEnvelopeEquality(ExportFormat $format): void
    {
        $client = $this->authenticatedClient();
        $tenant = $this->tenant();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $em = $this->em();

        $objectType = $em->getRepository(ObjectType::class)
            ->find(Uuid::fromString($this->objectTypeIdFor(ObjectKind::Product)));
        \assert($objectType instanceof ObjectType);

        // ── Seed: attributes (one per type), options, category, product ──
        /** @var array<string, Attribute> $attributes */
        $attributes = [];
        foreach (self::MATRIX as $code => [$type]) {
            $attribute = new Attribute($code, ['en' => $code], $type);
            $em->persist($attribute);
            $em->persist(new ObjectTypeAttribute($objectType, $attribute));
            $attributes[$code] = $attribute;
        }
        $name = new Attribute('gr_name', ['en' => 'Name'], AttributeType::Text);
        $name->changeLocalizable(true);
        $em->persist($name);
        $em->persist(new ObjectTypeAttribute($objectType, $name));
        $attributes['gr_name'] = $name;

        // IMP2-1.6 (#1469): a localizable + scopable attribute drives the
        // channel and locale+channel round-trip rows of the matrix.
        $chan = new Attribute('gr_chan', ['en' => 'Channel-scoped'], AttributeType::Text);
        $chan->changeLocalizable(true);
        $chan->changeScopable(true);
        $em->persist($chan);
        $em->persist(new ObjectTypeAttribute($objectType, $chan));
        $attributes['gr_chan'] = $chan;

        $em->persist(new AttributeOption($attributes['gr_select'], 'red', ['en' => 'Red'], 1));
        $em->persist(new AttributeOption($attributes['gr_multiselect'], 'new', ['en' => 'New'], 1));
        $em->persist(new AttributeOption($attributes['gr_multiselect'], 'sale', ['en' => 'Sale'], 2));

        // Active tenant locale 'en_US' (language 'en') — the column grammar
        // (IMP2-1.6) validates header suffixes against this registry; the
        // schema-built test DB ships no locale seeds.
        $enLocale = new \App\Channel\Domain\Entity\Locale('en_US', 'English (US)', null, 'en');
        $em->persist($enLocale);
        $em->persist(new \App\Channel\Domain\Entity\TenantLocale($enLocale));

        // Channel 'shopify' — the grammar resolves `gr_chan.shopify` /
        // `gr_chan.en.shopify` headers against it (tenant stamped by the
        // listener from the TenantContext set above).
        $shopify = new \App\Channel\Domain\Entity\Channel('shopify', 'Shopify');
        $em->persist($shopify);
        $shopifyId = $shopify->getId();

        $categoryType = $em->getRepository(ObjectType::class)
            ->find(Uuid::fromString($this->objectTypeIdFor(ObjectKind::Category)));
        \assert($categoryType instanceof ObjectType);
        $category = new CatalogObject($categoryType, 'gr-cat');
        $category2 = new CatalogObject($categoryType, 'gr-cat2');
        $em->persist($category);
        $em->persist($category2);

        $product = new CatalogObject($objectType, 'GR-001');
        // IMP2-1.7: non-default status/enabled exercise the column round-trip.
        $product->forceStatus('published');
        $product->changeEnabled(false);
        $em->persist($product);
        foreach (self::MATRIX as $code => [, $envelope]) {
            $em->persist(new ObjectValue($product, $attributes[$code], $envelope, Provenance::Manual));
        }
        $em->persist(new ObjectValue($product, $name, ['value' => 'Uchwyt globalny'], Provenance::Manual));
        $em->persist(new ObjectValue($product, $name, ['value' => 'Uchwyt PL'], Provenance::Manual, null, 'en'));
        // gr_chan: global, channel-only, and locale+channel scopes (IMP2-1.6).
        $em->persist(new ObjectValue($product, $chan, ['value' => 'Chan global'], Provenance::Manual));
        $em->persist(new ObjectValue($product, $chan, ['value' => 'Chan shopify'], Provenance::Manual, $shopifyId));
        $em->persist(new ObjectValue($product, $chan, ['value' => 'Chan EN shopify'], Provenance::Manual, $shopifyId, 'en'));
        $em->persist(new ObjectCategory(product: $product, category: $category, isPrimary: true, position: 0));
        $em->persist(new ObjectCategory(product: $product, category: $category2, isPrimary: false, position: 1));
        $em->flush();
        $productId = $product->getId();

        $expected = $this->envelopesOf($productId);
        self::assertNotSame([], $expected);

        // ── Act 1: real exporter → CSV ──
        $columns = array_merge(
            ['sku', 'category'],
            array_keys(self::MATRIX),
            ['gr_name', 'gr_name.en', 'gr_chan', 'gr_chan.shopify', 'gr_chan.en.shopify', 'status', 'enabled'],
        );
        $file = $this->exportToFile($tenant, $columns, [$productId], $format, ['shopify']);
        $csv = ExportFormat::Csv === $format ? (string) file_get_contents($file) : '';
        if (ExportFormat::Csv === $format) {
            self::assertStringContainsString('GR-001', $csv);
            self::assertStringContainsString('249.99 PLN', $csv, 'price serialises as "amount CUR"');
            self::assertStringContainsString('Chan EN shopify', $csv, 'combined locale+channel cell present in export');
        }

        // ── Act 2: real import (UPSERT) of that very file ──
        $mapping = ['sku' => 'sku', 'category' => '__category__'];
        foreach (array_keys(self::MATRIX) as $code) {
            $mapping[$code] = $code;
        }
        $mapping['gr_name'] = 'gr_name';
        $mapping['gr_name.en'] = 'gr_name';
        $mapping['gr_chan'] = 'gr_chan';
        $mapping['gr_chan.shopify'] = 'gr_chan';
        $mapping['gr_chan.en.shopify'] = 'gr_chan';
        $mapping['status'] = '__status__';
        $mapping['enabled'] = '__enabled__';

        $body = $this->postImportFile($client, $file, $objectType->getId()->toRfc4122(), $mapping);
        @unlink($file);
        $sessionId = $body['id'];
        \assert(\is_string($sessionId));
        self::assertSame([], $this->reportRows($client, $sessionId), 'no findings expected');

        // Read the outcome from the persisted session, not the response body:
        // xlsx routes through the async path (the controller can't pre-count
        // xlsx rows), and although the sync transport completes it inline, the
        // response carries the pre-dispatch `running` snapshot. The DB row is
        // the contract for both formats.
        $em->clear();
        $session = $em->find(ImportSession::class, Uuid::fromString($sessionId));
        \assert($session instanceof ImportSession);
        self::assertSame(ImportSessionStatus::Success, $session->getStatus());
        self::assertSame(1, $session->getSuccessCount());
        self::assertSame(1, $session->getUpdatedCount(), 'UPSERT must update, not duplicate');
        self::assertSame(0, $session->getErrorCount());

        // Same object — no duplicate row was created.
        $em->clear();
        $count = $em->getConnection()->fetchOne(
            "SELECT count(*) FROM objects WHERE code = 'GR-001'",
        );
        self::assertSame(1, (int) (\is_scalar($count) ? $count : 0));

        // ── Assert: envelope equality after the round-trip ──
        $actual = $this->envelopesOf($productId);
        foreach ($expected as $key => $envelope) {
            self::assertArrayHasKey($key, $actual, \sprintf('value "%s" lost in round-trip', $key));
            self::assertEnvelopeEquals($envelope, $actual[$key], $key);
        }

        // ── Assert: IMP2-1.7 — categories (replace, primary + order) and
        // status/enabled round-trip 1:1. ──
        $catCodes = $em->getConnection()->fetchFirstColumn(
            <<<'SQL'
                SELECT c.code FROM object_categories oc
                JOIN objects c ON c.id = oc.category_id
                JOIN objects o ON o.id = oc.object_id
                WHERE o.code = 'GR-001' ORDER BY oc.position
                SQL,
        );
        self::assertSame(['gr-cat', 'gr-cat2'], $catCodes, 'both categories survive the round-trip, in order');
        $primaryCode = $em->getConnection()->fetchOne(
            <<<'SQL'
                SELECT c.code FROM object_categories oc
                JOIN objects c ON c.id = oc.category_id
                JOIN objects o ON o.id = oc.object_id
                WHERE o.code = 'GR-001' AND oc.is_primary = true
                SQL,
        );
        self::assertSame('gr-cat', $primaryCode, 'first category stays primary');

        $reloaded = $em->find(CatalogObject::class, $productId);
        \assert($reloaded instanceof CatalogObject);
        self::assertSame('published', $reloaded->getStatus());
        self::assertFalse($reloaded->isEnabled());

        // ── Act 3: edit one cell → re-import updates exactly that value ──
        // CSV-only — the act edits the serialised text directly; the xlsx run
        // already proved the binary round-trip above via envelope equality.
        if (ExportFormat::Csv === $format) {
            $edited = str_replace('Uchwyt globalny', 'Uchwyt v2', $csv);
            self::assertNotSame($csv, $edited);
            $body2 = $this->postImport($client, $edited, $objectType->getId()->toRfc4122(), $mapping);
            self::assertSame(1, $body2['updated_count']);

            $after = $this->envelopesOf($productId);
            self::assertSame('Uchwyt v2', $after['gr_name||']['value'] ?? null);
            unset($expected['gr_name||'], $after['gr_name||']);
            foreach ($expected as $key => $envelope) {
                self::assertEnvelopeEquals($envelope, $after[$key], $key.' (po edycji innej komórki)');
            }
        }
    }

    #[Test]
    public function exportIncludeVariantsFansOutMasterBeforeItsVariants(): void
    {
        // IMP2-1.8 (AC) — include_variants=true emits each master followed by
        // its variants (parent_sku filled); =false emits masters only.
        $this->authenticatedClient();
        $tenant = $this->tenant();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $em = $this->em();
        $productType = $em->getRepository(ObjectType::class)
            ->find(Uuid::fromString($this->objectTypeIdFor(ObjectKind::Product)));
        \assert($productType instanceof ObjectType);

        $master = new CatalogObject($productType, 'FAN-M');
        $em->persist($master);
        $v1 = new CatalogObject($productType, 'FAN-V1');
        $v1->assignParent($master);
        $v2 = new CatalogObject($productType, 'FAN-V2');
        $v2->assignParent($master);
        $em->persist($v1);
        $em->persist($v2);
        $em->flush();
        $masterId = $master->getId();

        $csvOn = $this->runProductExport($tenant, ['sku', 'parent_sku'], [$masterId], [], true);
        self::assertStringContainsString('FAN-V1', $csvOn, 'variants exported when include_variants=true');
        self::assertStringContainsString('FAN-V2', $csvOn);
        self::assertMatchesRegularExpression('/FAN-V1\W+FAN-M/', $csvOn, 'variant row carries parent_sku');
        self::assertLessThan(
            (int) strpos($csvOn, 'FAN-V1'),
            (int) strpos($csvOn, 'FAN-M'),
            'master row precedes its variant rows',
        );

        $csvOff = $this->runProductExport($tenant, ['sku', 'parent_sku'], [$masterId], [], false);
        self::assertStringContainsString('FAN-M', $csvOff);
        self::assertStringNotContainsString('FAN-V1', $csvOff, 'variants excluded when include_variants=false');
        self::assertStringNotContainsString('FAN-V2', $csvOff);
    }

    #[Test]
    public function variantAxesRoundTripsViaFullShape(): void
    {
        // IMP2-1.8 (AC) — variant_axes round-trips 1:1 via the full
        // `code:value,value|code:value` shape.
        $client = $this->authenticatedClient();
        $tenant = $this->tenant();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $em = $this->em();
        $productType = $em->getRepository(ObjectType::class)
            ->find(Uuid::fromString($this->objectTypeIdFor(ObjectKind::Product)));
        \assert($productType instanceof ObjectType);

        $master = new CatalogObject($productType, 'VA-1');
        $master->declareVariantAxes([
            ['code' => 'color', 'values' => ['red', 'blue']],
            ['code' => 'size', 'values' => ['m']],
        ]);
        $em->persist($master);
        $em->flush();
        $masterId = $master->getId();

        $csv = $this->runProductExport($tenant, ['sku', 'variant_axes'], [$masterId]);
        self::assertStringContainsString('color:red,blue', $csv);
        self::assertStringContainsString('size:m', $csv);

        // Re-import as a NEW object (rename the sku) → variant_axes parsed back.
        $reimport = str_replace('VA-1', 'VA-2', $csv);
        $this->postImport($client, $reimport, $productType->getId()->toRfc4122(), [
            'sku' => 'sku',
            'variant_axes' => '__variant_axes__',
        ]);

        $em->clear();
        $stored = $em->getConnection()->fetchOne("SELECT variant_axes FROM objects WHERE code = 'VA-2'");
        \assert(\is_string($stored));
        self::assertSame(
            [
                ['code' => 'color', 'values' => ['red', 'blue']],
                ['code' => 'size', 'values' => ['m']],
            ],
            json_decode($stored, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function goldenRoundTripCarriesVariantsRelationsAndGallery(): void
    {
        // IMP2-1.8 (AC item 10) — master + 2 variants + a relation + a 2-asset
        // gallery survive export(include_variants=true) → import with their full
        // structure: parent_id, object_relations (by code), and the asset list.
        $client = $this->authenticatedClient();
        $tenant = $this->tenant();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $em = $this->em();
        $productType = $em->getRepository(ObjectType::class)
            ->find(Uuid::fromString($this->objectTypeIdFor(ObjectKind::Product)));
        \assert($productType instanceof ObjectType);

        // Relation + gallery attributes on the product type.
        $acc = new Attribute('acc', ['en' => 'Accessories'], AttributeType::Relation);
        $acc->setRelationTargetObjectTypeIds([$productType->getId()->toRfc4122()]);
        $acc->setRelationCardinality(\App\Catalog\Domain\RelationCardinality::Many);
        $em->persist($acc);
        $em->persist(new ObjectTypeAttribute($productType, $acc));
        $gal = new Attribute('gal', ['en' => 'Gallery'], AttributeType::Asset);
        $em->persist($gal);
        $em->persist(new ObjectTypeAttribute($productType, $gal));

        $assetA = new \App\Asset\Domain\Entity\Asset('G-A', 'a.jpg', 'image/jpeg', 10, 'p/a.jpg');
        $assetB = new \App\Asset\Domain\Entity\Asset('G-B', 'b.jpg', 'image/jpeg', 10, 'p/b.jpg');
        $em->persist($assetA);
        $em->persist($assetB);

        $master = new CatalogObject($productType, 'MA-M');
        $v1 = new CatalogObject($productType, 'MA-V1');
        $v1->assignParent($master);
        $v2 = new CatalogObject($productType, 'MA-V2');
        $v2->assignParent($master);
        $relTarget = new CatalogObject($productType, 'MA-REL');
        $em->persist($master);
        $em->persist($v1);
        $em->persist($v2);
        $em->persist($relTarget);
        $em->flush();

        $idA = $assetA->getId()->toRfc4122();
        $idB = $assetB->getId()->toRfc4122();
        $em->persist(new \App\Catalog\Domain\Entity\ObjectRelation($master, $relTarget, $acc, 0));
        $em->persist(new ObjectValue($master, $gal, ['asset_id' => [$idA, $idB]], Provenance::Manual));
        $em->flush();
        $masterId = $master->getId();
        $relTargetId = $relTarget->getId();

        // Export master + relation target (both selected) with variant fan-out.
        $csv = $this->runProductExport($tenant, ['sku', 'parent_sku', 'acc', 'gal'], [$masterId, $relTargetId], [], true);
        foreach (['MA-M', 'MA-V1', 'MA-V2', 'MA-REL'] as $code) {
            self::assertStringContainsString($code, $csv, $code.' present in export');
        }
        self::assertStringContainsString($idA.'|'.$idB, $csv, 'gallery exported as pipe-joined asset ids');

        // Re-import as a fresh tree (MA- → RT-): parent_sku + relation codes
        // rename together; asset ids stay (the assets already exist).
        $reimport = str_replace('MA-', 'RT-', $csv);
        $body = $this->postImport($client, $reimport, $productType->getId()->toRfc4122(), [
            'sku' => 'sku',
            'parent_sku' => '__parent_sku__',
            'acc' => 'acc',
            'gal' => 'gal',
        ]);
        self::assertSame('success', $body['status']);
        self::assertSame(4, $body['success_count']);

        $em->clear();
        // Variants point at the freshly-imported master (two-pass parent link).
        $parents = $em->getConnection()->fetchAllKeyValue(
            "SELECT o.code, p.code FROM objects o JOIN objects p ON p.id = o.parent_id WHERE o.code IN ('RT-V1','RT-V2') ORDER BY o.code",
        );
        self::assertSame(['RT-V1' => 'RT-M', 'RT-V2' => 'RT-M'], $parents);

        // Relation resolved by code into object_relations (RT-M → RT-REL).
        $relTargets = $em->getConnection()->fetchFirstColumn(
            "SELECT t.code FROM object_relations r JOIN objects s ON s.id=r.source_object_id JOIN objects t ON t.id=r.target_object_id JOIN attributes a ON a.id=r.attribute_id WHERE s.code='RT-M' AND a.code='acc'",
        );
        self::assertSame(['RT-REL'], $relTargets);

        // Gallery asset list survived intact and in order.
        $galValue = $em->getConnection()->fetchOne(
            "SELECT ov.value FROM object_values ov JOIN attributes a ON a.id=ov.attribute_id JOIN objects o ON o.id=ov.object_id WHERE a.code='gal' AND o.code='RT-M'",
        );
        \assert(\is_string($galValue));
        self::assertSame(['asset_id' => [$idA, $idB]], json_decode($galValue, true, 512, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function generatedVariantsRoundTripOneToOne(): void
    {
        // IMP2-1.8 (AC item 9) — a master whose variants come from the REAL
        // GenerateVariants endpoint (canonical select-axis shape, D7) exports
        // with parent_sku + axes and re-imports 1:1.
        $client = $this->authenticatedClient();
        $tenant = $this->tenant();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $em = $this->em();
        $productType = $em->getRepository(ObjectType::class)
            ->find(Uuid::fromString($this->objectTypeIdFor(ObjectKind::Product)));
        \assert($productType instanceof ObjectType);

        $color = new Attribute('color', ['en' => 'Color'], AttributeType::Select);
        $em->persist($color);
        $em->persist(new ObjectTypeAttribute($productType, $color));
        $em->persist(new AttributeOption($color, 'red', ['en' => 'Red'], 1));
        $em->persist(new AttributeOption($color, 'blue', ['en' => 'Blue'], 2));
        $master = new CatalogObject($productType, 'GV-1');
        $em->persist($master);
        $em->flush();
        $masterId = $master->getId();

        // Generate two variants through the real endpoint.
        $client->request('POST', '/api/products/'.$masterId->toRfc4122().'/generate-variants', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['axes' => ['color' => ['red', 'blue']]], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseIsSuccessful();

        // Export master + its generated variants.
        $csv = $this->runProductExport($tenant, ['sku', 'parent_sku', 'variant_axes', 'color'], [$masterId], [], true);
        self::assertStringContainsString('GV-1-RED', $csv);
        self::assertStringContainsString('GV-1-BLUE', $csv);
        self::assertStringContainsString('color:red,blue', $csv, 'master carries the canonical axes definition');

        // Re-import as a fresh tree (GV-1 → RT-1 renames master + variant skus
        // + the parent_sku cell together; option codes stay).
        $reimport = str_replace('GV-1', 'RT-1', $csv);
        $body = $this->postImport($client, $reimport, $productType->getId()->toRfc4122(), [
            'sku' => 'sku',
            'parent_sku' => '__parent_sku__',
            'variant_axes' => '__variant_axes__',
            'color' => 'color',
        ]);
        self::assertSame('success', $body['status']);
        self::assertSame(3, $body['success_count']);

        $em->clear();
        // Variants reparented to the freshly-imported master.
        $parents = $em->getConnection()->fetchAllKeyValue(
            "SELECT o.code, p.code FROM objects o JOIN objects p ON p.id = o.parent_id WHERE o.code IN ('RT-1-RED','RT-1-BLUE') ORDER BY o.code",
        );
        self::assertSame(['RT-1-BLUE' => 'RT-1', 'RT-1-RED' => 'RT-1'], $parents);

        // Master axes definition survived intact.
        $axes = $em->getConnection()->fetchOne("SELECT variant_axes FROM objects WHERE code = 'RT-1'");
        \assert(\is_string($axes));
        self::assertSame(
            [['code' => 'color', 'values' => ['red', 'blue']]],
            json_decode($axes, true, 512, JSON_THROW_ON_ERROR),
        );

        // Each variant kept its axis option code.
        $redOption = $em->getConnection()->fetchOne(
            "SELECT ov.value FROM object_values ov JOIN attributes a ON a.id=ov.attribute_id JOIN objects o ON o.id=ov.object_id WHERE a.code='color' AND o.code='RT-1-RED'",
        );
        \assert(\is_string($redOption));
        self::assertSame(['option_code' => 'red'], json_decode($redOption, true, 512, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function selectStoredCanonicallyExportsAsNonEmptyOptionCode(): void
    {
        // IMP2-1.2 (#1464, ADR-0019) — a select value stored in the CANONICAL
        // shape `{option_code: "<code>"}` must export to a NON-EMPTY cell equal
        // to the option code. This guards the ValueSerializer Select branch
        // (`pickKey('option_code')`) against the legacy `{value}` regression the
        // migration eliminates: a legacy envelope would serialise to "".
        $this->authenticatedClient();
        $tenant = $this->tenant();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $em = $this->em();

        $productType = $em->getRepository(ObjectType::class)
            ->find(Uuid::fromString($this->objectTypeIdFor(ObjectKind::Product)));
        \assert($productType instanceof ObjectType);

        $color = new Attribute('canon_color', ['en' => 'Color'], AttributeType::Select);
        $em->persist($color);
        $em->persist(new ObjectTypeAttribute($productType, $color));
        $em->persist(new AttributeOption($color, 'red', ['en' => 'Red'], 1));

        $product = new CatalogObject($productType, 'CANON-EXP-1');
        $em->persist($product);
        // Canonical envelope — exactly what the migration produces from {value:"red"}.
        $em->persist(new ObjectValue($product, $color, ['option_code' => 'red'], Provenance::Manual));
        $em->flush();
        $productId = $product->getId();

        $csv = $this->runProductExport($tenant, ['sku', 'canon_color'], [$productId]);

        // The select cell carries the option code verbatim — not blank.
        self::assertStringContainsString('CANON-EXP-1', $csv);
        $cell = $this->cellFor($csv, 'canon_color', 'CANON-EXP-1');
        self::assertNotSame('', $cell, 'canonical select must export a non-empty cell');
        self::assertSame('red', $cell, 'select cell equals the stored option_code');
    }

    #[Test]
    public function everyAttributeTypeHasGoldenCoverage(): void
    {
        // IMP2-1.10 (AC item 4) — every AttributeType is exercised by the
        // golden suite, and the coverage is DERIVED (not a 17×4 cartesian):
        //  - 14 value types ride the envelope matrix in both CSV + xlsx, each
        //    with only the scopes its isLocalizable/isScopable flags permit
        //    (gr_name adds locale rows, gr_chan adds channel + locale+channel);
        //  - asset rides the 2-image gallery round-trip;
        //  - relation + reference write object_relations (NOT object_values),
        //    so they ride the relation round-trip instead of the envelope diff.
        // A new AttributeType added without coverage flips this red.
        $matrixTypes = array_map(static fn (array $entry): AttributeType => $entry[0], array_values(self::MATRIX));
        $covered = array_merge($matrixTypes, [
            AttributeType::Asset,
            AttributeType::Relation,
            AttributeType::Reference,
        ]);

        foreach (AttributeType::cases() as $type) {
            self::assertContains(
                $type,
                $covered,
                \sprintf('AttributeType "%s" has no golden round-trip coverage.', $type->value),
            );
        }
    }

    #[Test]
    public function legacyEnvelopesAreNormalisedToTheCanonByTheWriteSide(): void
    {
        // IMP2-1.5 (#1467, AC) — a pre-canon DB row (raw-INSERT legacy shape:
        // select `{value:"red"}` instead of `{option_code:"red"}`, price
        // `{value:99.99}` without currency) is rewritten to the per-type canon
        // (ADR-0019 D-1.2 / ValueWriteCore::canonicalise) when the real engine
        // re-imports the exported file. Proves the WRITE side normalises legacy,
        // not just that fresh writes start canonical.
        $client = $this->authenticatedClient();
        $tenant = $this->tenant();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $em = $this->em();

        $objectType = $em->getRepository(ObjectType::class)
            ->find(Uuid::fromString($this->objectTypeIdFor(ObjectKind::Product)));
        \assert($objectType instanceof ObjectType);

        $select = new Attribute('lg_select', ['en' => 'Legacy select'], AttributeType::Select);
        $price = new Attribute('lg_price', ['en' => 'Legacy price'], AttributeType::Price);
        $em->persist($select);
        $em->persist($price);
        $em->persist(new ObjectTypeAttribute($objectType, $select));
        $em->persist(new ObjectTypeAttribute($objectType, $price));
        $em->persist(new AttributeOption($select, 'red', ['en' => 'Red'], 1));

        $product = new CatalogObject($objectType, 'LG-001');
        $em->persist($product);
        $em->flush();
        $productId = $product->getId();

        // Raw-INSERT the LEGACY envelopes the canon would never produce — the
        // ObjectValue hydration / writers emit `option_code` / `amount`, so the
        // pre-canon shape can only be planted directly on the connection.
        $conn = $em->getConnection();
        $tenantId = $tenant->getId()->toRfc4122();
        $insertLegacy = static function (string $attributeId, string $json) use ($conn, $tenantId, $productId): void {
            $conn->insert('object_values', [
                'id' => Uuid::v7()->toRfc4122(),
                'tenant_id' => $tenantId,
                'object_id' => $productId->toRfc4122(),
                'attribute_id' => $attributeId,
                'value' => $json,
                'provenance' => 'import',
                'provenance_meta' => '{}',
            ]);
        };
        $insertLegacy($select->getId()->toRfc4122(), '{"value": "red"}');
        // AUD-032: the legacy price wrap carries its currency sibling. The
        // write side rewrites the `value` KEY to `amount` (the normalisation
        // under test); the price validator — now enforced for every type —
        // requires the currency, so a currency-less legacy wrap would be
        // (correctly) rejected on re-import and never reach the canon. The
        // canon migration (Version20260612210000) documents the same: a
        // currency-less migrated price stays incomplete until the next edit.
        $insertLegacy($price->getId()->toRfc4122(), '{"value": 99.99, "currency": "PLN"}');

        // Sanity: the seeded rows really are legacy, not canon. Read straight
        // off the connection so the managed Tenant the exporter needs stays
        // attached (no $em->clear() before runToFile, which re-persists it).
        $before = $this->legacyEnvelopesOf($productId);
        self::assertSame(['value' => 'red'], $before['lg_select'], 'select seeded as legacy {value}');
        self::assertSame(['value' => 99.99, 'currency' => 'PLN'], $before['lg_price'], 'price seeded as legacy {value} + currency');

        // ── Round-trip through the REAL export → import engine ──
        $columns = ['sku', 'lg_select', 'lg_price'];
        $file = $this->exportToFile($tenant, $columns, [$productId], ExportFormat::Csv);
        $csv = (string) file_get_contents($file);
        @unlink($file);
        // The Select serialiser reads `option_code` ONLY — a legacy select cell
        // exports empty (it never had the canonical key). Inject the option code
        // so the re-import has a cell to canonicalise; price exports fine because
        // its serialiser falls back to `value` (#1271) and appends the currency
        // sibling, so it rides as "99.99 PLN".
        // The exporter writes `;`-delimited cells behind a UTF-8 BOM (#1484).
        self::assertStringContainsString('99.99 PLN', $csv, 'legacy price exports amount + currency via the value fallback');
        $clean = ltrim($csv, "\xEF\xBB\xBF");
        $lines = explode("\n", trim($clean));
        self::assertCount(2, $lines, 'header + one data row');
        // str_getcsv yields a list<string|null>; normalise nulls to '' for the
        // splice (a legacy-select empty cell parses as the empty string anyway).
        $cells = array_map(static fn (?string $cell): string => $cell ?? '', str_getcsv($lines[1], ';', '"', '\\'));
        // Header order = $columns: sku, lg_select, lg_price.
        self::assertSame('', $cells[1] ?? '', 'legacy select exports empty (no option_code key)');
        $cells[1] = 'red';
        $edited = $lines[0]."\n".$this->joinCsvRow($cells)."\n";

        $body = $this->postImport($client, $edited, $objectType->getId()->toRfc4122(), [
            'sku' => 'sku',
            'lg_select' => 'lg_select',
            'lg_price' => 'lg_price',
        ]);
        self::assertSame('success', $body['status']);

        // ── Assert: the legacy rows are now CANON ──
        $em->clear();
        $after = $this->legacyEnvelopesOf($productId);
        self::assertSame(['option_code' => 'red'], $after['lg_select'], 'select normalised {value}→{option_code}');
        self::assertSame(['amount' => 99.99, 'currency' => 'PLN'], $after['lg_price'], 'price normalised {value}→{amount}, currency preserved');
        self::assertArrayNotHasKey('value', $after['lg_select'], 'no legacy key survives on select');
        self::assertArrayNotHasKey('value', $after['lg_price'], 'no legacy key survives on price');
    }

    #[Test]
    public function reImportingAFileWithANewRowCreatesTheNewObject(): void
    {
        // IMP2-1.5 (#1467, AC — the "edit" scenario, create branch) — appending
        // a brand-new SKU to the exported file and re-importing in UPSERT both
        // updates the original row AND creates the new object. The import domain
        // has no created_count column, so the create is read as
        // (successCount − updatedCount) plus the new row's existence in the DB.
        $client = $this->authenticatedClient();
        $tenant = $this->tenant();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $em = $this->em();

        $objectType = $em->getRepository(ObjectType::class)
            ->find(Uuid::fromString($this->objectTypeIdFor(ObjectKind::Product)));
        \assert($objectType instanceof ObjectType);

        $title = new Attribute('gr_title', ['en' => 'Title'], AttributeType::Text);
        $em->persist($title);
        $em->persist(new ObjectTypeAttribute($objectType, $title));
        $product = new CatalogObject($objectType, 'GR-EXIST');
        $em->persist($product);
        $em->persist(new ObjectValue($product, $title, ['value' => 'Original'], Provenance::Manual));
        $em->flush();
        $productId = $product->getId();

        // ── Export the single existing object ──
        $file = $this->exportToFile($tenant, ['sku', 'gr_title'], [$productId], ExportFormat::Csv);
        $csv = (string) file_get_contents($file);
        @unlink($file);
        self::assertStringContainsString('GR-EXIST', $csv);

        // ── Edit: update the existing row + append a brand-new SKU ──
        // The exporter writes `;`-delimited cells (CsvStreamWriter) — the new row
        // MUST match or the detector parses it as a single column.
        $edited = str_replace('Original', 'Zaktualizowany', rtrim($csv, "\n"))."\nGR-NEW;Nowy produkt\n";
        $mapping = ['sku' => 'sku', 'gr_title' => 'gr_title'];

        $body = $this->postImport($client, $edited, $objectType->getId()->toRfc4122(), $mapping);
        self::assertSame('success', $body['status']);
        // 1 update (GR-EXIST changed) + 1 create (GR-NEW) = 2 successes.
        self::assertSame(2, $body['success_count'], 'one update + one create');
        self::assertSame(1, $body['updated_count'], 'only the pre-existing row counts as updated');
        self::assertSame(0, $body['error_count']);
        // created = successCount − updatedCount (no created_count in the domain).
        self::assertSame(1, $body['success_count'] - $body['updated_count'], 'exactly one new object created');

        // ── Assert: the new object exists and carries its imported value ──
        $em->clear();
        $newCount = $em->getConnection()->fetchOne("SELECT count(*) FROM objects WHERE code = 'GR-NEW'");
        self::assertSame(1, (int) (\is_scalar($newCount) ? $newCount : 0), 'GR-NEW object created');
        $newTitle = $em->getConnection()->fetchOne(
            "SELECT ov.value->>'value' FROM object_values ov JOIN attributes a ON a.id=ov.attribute_id JOIN objects o ON o.id=ov.object_id WHERE a.code='gr_title' AND o.code='GR-NEW'",
        );
        self::assertSame('Nowy produkt', \is_scalar($newTitle) ? (string) $newTitle : null);

        // ── Assert: the original row was updated, not duplicated ──
        $existCount = $em->getConnection()->fetchOne("SELECT count(*) FROM objects WHERE code = 'GR-EXIST'");
        self::assertSame(1, (int) (\is_scalar($existCount) ? $existCount : 0), 'no duplicate of the existing object');
        $updatedTitle = $em->getConnection()->fetchOne(
            "SELECT ov.value->>'value' FROM object_values ov JOIN attributes a ON a.id=ov.attribute_id JOIN objects o ON o.id=ov.object_id WHERE a.code='gr_title' AND o.code='GR-EXIST'",
        );
        self::assertSame('Zaktualizowany', \is_scalar($updatedTitle) ? (string) $updatedTitle : null);
    }

    /**
     * @return array<string, array<string, mixed>> attribute code => envelope
     */
    private function legacyEnvelopesOf(Uuid $productId): array
    {
        $rows = $this->em()->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT a.code, ov.value
                FROM object_values ov
                JOIN attributes a ON a.id = ov.attribute_id
                WHERE ov.object_id = :id AND a.code LIKE 'lg_%'
                SQL,
            ['id' => $productId->toRfc4122()],
        );

        $map = [];
        foreach ($rows as $row) {
            $raw = $row['value'];
            $code = $row['code'];
            \assert(\is_string($raw) && \is_string($code));
            /** @var array<string, mixed> $envelope */
            $envelope = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $map[$code] = $envelope;
        }

        return $map;
    }

    /**
     * Re-emit a parsed CSV row with the exporter's `;` delimiter (CsvStreamWriter)
     * and RFC-4180 quoting (quote a field iff it holds the delimiter, a quote or
     * a newline; double inner quotes). Splices an edited cell back into a line.
     *
     * @param list<string> $cells
     */
    private function joinCsvRow(array $cells): string
    {
        return implode(';', array_map(static function (string $cell): string {
            if (false === strpbrk($cell, ";\"\n\r")) {
                return $cell;
            }

            return '"'.str_replace('"', '""', $cell).'"';
        }, $cells));
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    private static function assertEnvelopeEquals(array $expected, array $actual, string $context): void
    {
        ksort($expected);
        ksort($actual);
        // ADR-0019 normalisation v1: numeric string ≡ number.
        $normalise = static function (array $envelope): array {
            foreach (['value', 'amount'] as $key) {
                if (isset($envelope[$key]) && \is_string($envelope[$key]) && is_numeric($envelope[$key])) {
                    $envelope[$key] = (float) $envelope[$key];
                }
                if (isset($envelope[$key]) && (\is_int($envelope[$key]) || \is_float($envelope[$key]))) {
                    $envelope[$key] = (float) $envelope[$key];
                }
            }

            return $envelope;
        };
        self::assertSame($normalise($expected), $normalise($actual), \sprintf('envelope drift for "%s"', $context));
    }

    /**
     * @return array<string, array<string, mixed>> "code|locale|channelId" => envelope
     */
    private function envelopesOf(Uuid $productId): array
    {
        $rows = $this->em()->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT a.code, ov.locale, ov.channel_id, ov.value
                FROM object_values ov
                JOIN attributes a ON a.id = ov.attribute_id
                WHERE ov.object_id = :id AND a.code LIKE 'gr_%'
                ORDER BY a.code, ov.locale NULLS FIRST, ov.channel_id NULLS FIRST
                SQL,
            ['id' => $productId->toRfc4122()],
        );

        $map = [];
        foreach ($rows as $row) {
            $raw = $row['value'];
            \assert(\is_string($raw));
            /** @var array<string, mixed> $envelope */
            $envelope = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $code = $row['code'];
            $locale = $row['locale'];
            $channelId = $row['channel_id'];
            \assert(\is_string($code));
            $map[$code.'|'.(\is_string($locale) ? $locale : '').'|'.(\is_string($channelId) ? $channelId : '')] = $envelope;
        }

        return $map;
    }

    /**
     * @param list<string> $columns
     * @param list<Uuid>   $ids
     * @param list<string> $channels
     */
    private function runProductExport(Tenant $tenant, array $columns, array $ids, array $channels = [], bool $includeVariants = true): string
    {
        $session = new ExportSession(
            userId: Uuid::v7(),
            source: ExportSource::CentralTab,
            format: ExportFormat::Csv,
            targetScope: ExportTargetScope::Selected,
            selectedColumns: $columns,
            entityType: ExportEntityType::Product,
            selectedObjectIds: array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids),
            channels: $channels,
            includeVariants: $includeVariants,
        );
        $session->assignTenant($tenant);

        $runner = self::getContainer()->get(SyncExportRunner::class);
        $path = tempnam(sys_get_temp_dir(), 'golden-').'.csv';
        try {
            $runner->runToFile($session, $path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param array<string, string> $mapping
     *
     * @return array<string, mixed>
     */
    private function postImport(
        \ApiPlatform\Symfony\Bundle\Test\Client $client,
        string $csv,
        string $objectTypeId,
        array $mapping,
    ): array {
        $path = tempnam(sys_get_temp_dir(), 'golden-import-').'.csv';
        file_put_contents($path, $csv);

        try {
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $objectTypeId,
                        'mapping' => json_encode($mapping, JSON_THROW_ON_ERROR),
                        'mode' => 'UPSERT',
                    ],
                    'files' => [
                        'file' => new UploadedFile($path, 'golden.csv', 'text/csv', null, true),
                    ],
                ],
            ]);
            self::assertResponseIsSuccessful();
            $response = $client->getResponse();
            \assert(null !== $response);
            $content = $response->getContent();
            /** @var array<string, mixed> $body */
            $body = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            return $body;
        } finally {
            @unlink($path);
        }
    }

    /**
     * IMP2-1.10 — export the real file in the given format to a temp path with
     * the matching extension. The caller owns the file (the format matrix needs
     * the binary xlsx on disk for the import reader, not a string).
     *
     * @param list<string> $columns
     * @param list<Uuid>   $ids
     * @param list<string> $channels
     */
    private function exportToFile(Tenant $tenant, array $columns, array $ids, ExportFormat $format, array $channels = [], bool $includeVariants = true): string
    {
        $session = new ExportSession(
            userId: Uuid::v7(),
            source: ExportSource::CentralTab,
            format: $format,
            targetScope: ExportTargetScope::Selected,
            selectedColumns: $columns,
            entityType: ExportEntityType::Product,
            selectedObjectIds: array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids),
            channels: $channels,
            includeVariants: $includeVariants,
        );
        $session->assignTenant($tenant);

        $extension = ExportFormat::Xlsx === $format ? 'xlsx' : 'csv';
        $path = tempnam(sys_get_temp_dir(), 'golden-').'.'.$extension;
        $runner = self::getContainer()->get(SyncExportRunner::class);
        $runner->runToFile($session, $path);

        return $path;
    }

    /**
     * IMP2-1.10 — import a previously-exported file (CSV or xlsx) by path. The
     * reader picks the parser from the extension, so the same matrix rides both
     * formats through the real engine.
     *
     * @param array<string, string> $mapping
     *
     * @return array<string, mixed>
     */
    private function postImportFile(
        \ApiPlatform\Symfony\Bundle\Test\Client $client,
        string $path,
        string $objectTypeId,
        array $mapping,
    ): array {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = 'xlsx' === $extension
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'text/csv';

        $client->request('POST', '/api/import-sessions', [
            'extra' => [
                'parameters' => [
                    'target_object_type_id' => $objectTypeId,
                    'mapping' => json_encode($mapping, JSON_THROW_ON_ERROR),
                    'mode' => 'UPSERT',
                ],
                'files' => [
                    'file' => new UploadedFile($path, 'golden.'.$extension, $mime, null, true),
                ],
            ],
        ]);
        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        \assert(null !== $response);
        $content = $response->getContent();
        /** @var array<string, mixed> $body */
        $body = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $body;
    }

    /**
     * Resolve a single exported cell by column header + the SKU of its row.
     * Parses the real CSV the exporter wrote so the assertion reads the actual
     * serialised cell, not a substring match.
     */
    private function cellFor(string $csv, string $column, string $sku): string
    {
        // CsvStreamWriter writes a UTF-8 BOM prefix and a `;` separator
        // (Excel PL friendliness) — strip/parse accordingly.
        $csv = ltrim($csv, "\xEF\xBB\xBF");
        $lines = array_values(array_filter(
            explode("\n", trim($csv)),
            static fn (string $line): bool => '' !== trim($line),
        ));
        self::assertNotEmpty($lines, 'export produced no rows');

        $header = str_getcsv($lines[0], ';', '"', '\\');
        $columnIndex = array_search($column, $header, true);
        $skuIndex = array_search('sku', $header, true);
        self::assertIsInt($columnIndex, \sprintf('column "%s" missing from export header', $column));
        self::assertIsInt($skuIndex, 'sku column missing from export header');

        foreach (\array_slice($lines, 1) as $line) {
            $row = str_getcsv($line, ';', '"', '\\');
            if (($row[$skuIndex] ?? null) === $sku) {
                return $row[$columnIndex] ?? '';
            }
        }

        self::fail(\sprintf('no exported row for sku "%s"', $sku));
    }

    /** @return list<string> */
    private function reportRows(\ApiPlatform\Symfony\Bundle\Test\Client $client, string $sessionId): array
    {
        $client->request('GET', '/api/import-sessions/'.$sessionId.'/report.csv');
        $response = $client->getResponse();
        \assert(null !== $response);
        $content = $response->getContent();
        $lines = array_filter(explode("\n", trim($content)), static fn (string $line): bool => '' !== $line);

        return \array_slice($lines, 1);
    }

    private function tenant(): Tenant
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }
}
