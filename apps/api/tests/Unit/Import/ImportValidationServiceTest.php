<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Contracts\Query\ObjectTypeAttributeCodesPort;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Import\Application\Service\CompositeValueParser;
use App\Import\Application\Service\DelimiterDetector;
use App\Import\Application\Service\EncodingDetector;
use App\Import\Application\Service\ImportRowReader;
use App\Import\Application\Service\ImportValidationService;
use App\Import\Domain\Enum\ImportErrorType;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ImportValidationServiceTest extends TestCase
{
    #[Test]
    public function validatesMissingRequiredAndDuplicateSkuTypes(): void
    {
        $tenant = new Tenant('demo', 'Demo');
        $skuAttribute = new Attribute('sku', ['en' => 'SKU'], AttributeType::Text);
        $nameAttribute = new Attribute('name', ['en' => 'Name'], AttributeType::Text);
        $priceAttribute = new Attribute('price', ['en' => 'Price'], AttributeType::Number);

        $attributeRepo = new InMemoryAttributeRepository([
            'sku' => $skuAttribute,
            'name' => $nameAttribute,
            'price' => $priceAttribute,
        ]);

        $existingProduct = new CatalogObject(
            new ObjectType('product', ObjectKind::Product, ['en' => 'Product']),
            'EXISTING-1',
        );
        $catalogRepo = new InMemoryCatalogObjectRepository(['EXISTING-1' => $existingProduct]);

        $tenantContext = new TenantContext();
        $tenantContext->set($tenant);

        $service = new ImportValidationService(
            attributes: $attributeRepo,
            catalogObjects: $catalogRepo,
            tenantContext: $tenantContext,
            rowReader: new ImportRowReader(new EncodingDetector(), new DelimiterDetector()),
            compositeValueParser: new CompositeValueParser(),
            columnGrammar: $this->grammarWith(['pl', 'en']),
            objectTypeAttributeCodes: new InMemoryObjectTypeAttributeCodes(['sku']),
        );

        $csv = "sku;name;price\nOK-1;Foo;9.99\nEXISTING-1;Bar;14.99\n;Anon;5\nDUP-1;Dup;1\nDUP-1;Dup again;2\nBAD-1;Has bad price;not-a-number\n";
        $path = $this->writeTempCsv($csv);

        try {
            $product = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
            $result = $service->validate(
                absolutePath: $path,
                columnMapping: ['sku' => 'sku', 'name' => 'name', 'price' => 'price'],
                target: $product,
            );

            self::assertSame(6, $result->totalRows);
            // IMP2-1.3 (#1465): the in-DB duplicate check moved to the run
            // loop (ObjectResolver + ImportMode) — EXISTING-1 now validates
            // clean here; mode buckets surface in the dry-run rework (#1492).
            // IMP2-1.9: OK-1, EXISTING-1 and the first DUP-1 import (3); the
            // empty-SKU + bad-price rows error (2); the second DUP-1 is a
            // non-blocking skip (D1) — neither success nor error.
            self::assertSame(3, $result->successCount);
            self::assertSame(2, $result->errorCount);

            $errorTypes = array_map(static fn ($e): string => $e->errorType->value, $result->errors);
            self::assertNotContains(ImportErrorType::DuplicateSkuInDb->value, $errorTypes);
            self::assertContains(ImportErrorType::MissingRequired->value, $errorTypes);
            self::assertContains(ImportErrorType::DuplicateSkuInFile->value, $errorTypes);
            self::assertContains(ImportErrorType::InvalidType->value, $errorTypes);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function roundTripExportColumnsValidateWithoutBlockingErrors(): void
    {
        $tenant = new Tenant('demo', 'Demo');
        $attributeRepo = new InMemoryAttributeRepository([
            'sku' => new Attribute('sku', ['en' => 'SKU'], AttributeType::Text),
            'name' => new Attribute('name', ['en' => 'Name'], AttributeType::Text),
            'price' => new Attribute('price', ['en' => 'Price'], AttributeType::Price),
            'weight' => new Attribute('weight', ['en' => 'Weight'], AttributeType::Metric),
        ]);
        $catalogRepo = new InMemoryCatalogObjectRepository([]);

        $tenantContext = new TenantContext();
        $tenantContext->set($tenant);

        $service = new ImportValidationService(
            attributes: $attributeRepo,
            catalogObjects: $catalogRepo,
            tenantContext: $tenantContext,
            rowReader: new ImportRowReader(new EncodingDetector(), new DelimiterDetector()),
            compositeValueParser: new CompositeValueParser(),
            columnGrammar: $this->grammarWith(['pl', 'en']),
            objectTypeAttributeCodes: new InMemoryObjectTypeAttributeCodes(['sku']),
        );

        // The exporter's own format: a system column (created_at), a
        // localised name (name.pl) standing in for the bare required
        // `name`, and composite price / metric cells.
        $csv = "sku;created_at;name.pl;price;weight\n"
            ."RT-1;2026-05-28T20:14:59+00:00;Buty;20.99 EUR;0.3 g\n";
        $path = $this->writeTempCsv($csv);

        try {
            $product = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
            $result = $service->validate(
                absolutePath: $path,
                columnMapping: [
                    'sku' => 'sku',
                    'created_at' => 'skip',
                    'name.pl' => 'name',
                    'price' => 'price',
                    'weight' => 'weight',
                ],
                target: $product,
            );

            self::assertSame(1, $result->totalRows);
            self::assertSame(1, $result->successCount, 'Round-trip export row validates clean.');
            self::assertSame(0, $result->errorCount);
            self::assertSame([], $result->errors);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function customObjectTypeWithoutSkuValidatesWithoutMissingRequired(): void
    {
        // #2943 — a custom ObjectType ("Twórcy" in the report) models neither
        // `sku` nor `name`. Requiring `sku` unconditionally failed every row
        // on a column that type cannot have, and the operator had no way to
        // satisfy it. The write path never needed it: `objects.code` falls
        // back to `IMPORT-<row>`.
        $tenant = new Tenant('demo', 'Demo');
        $attributeRepo = new InMemoryAttributeRepository([
            'pseudonim' => new Attribute('pseudonim', ['pl' => 'Pseudonim'], AttributeType::Text),
        ]);

        $tenantContext = new TenantContext();
        $tenantContext->set($tenant);

        $service = new ImportValidationService(
            attributes: $attributeRepo,
            catalogObjects: new InMemoryCatalogObjectRepository([]),
            tenantContext: $tenantContext,
            rowReader: new ImportRowReader(new EncodingDetector(), new DelimiterDetector()),
            compositeValueParser: new CompositeValueParser(),
            columnGrammar: $this->grammarWith(['pl', 'en']),
            objectTypeAttributeCodes: new InMemoryObjectTypeAttributeCodes([]),
        );

        $csv = "pseudonim\nKowalski\nNowak\n";
        $path = $this->writeTempCsv($csv);

        try {
            $result = $service->validate(
                absolutePath: $path,
                columnMapping: ['pseudonim' => 'pseudonim'],
                target: new ObjectType('tworcy', ObjectKind::Custom, ['pl' => 'Twórcy']),
            );

            self::assertSame(2, $result->totalRows);
            self::assertSame(2, $result->successCount);
            self::assertSame(0, $result->errorCount);
            $errorTypes = array_map(static fn ($e): string => $e->errorType->value, $result->errors);
            self::assertNotContains(ImportErrorType::MissingRequired->value, $errorTypes);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function configuredMatchAttributeBecomesTheRequiredIdentityColumn(): void
    {
        // A run matching on EAN (ADR-0019 D1) cannot resolve a row without it,
        // so that column — not `sku` — is what the row must carry.
        $tenant = new Tenant('demo', 'Demo');
        $attributeRepo = new InMemoryAttributeRepository([
            'ean' => new Attribute('ean', ['en' => 'EAN'], AttributeType::Identifier),
            'name' => new Attribute('name', ['en' => 'Name'], AttributeType::Text),
        ]);

        $tenantContext = new TenantContext();
        $tenantContext->set($tenant);

        $service = new ImportValidationService(
            attributes: $attributeRepo,
            catalogObjects: new InMemoryCatalogObjectRepository([]),
            tenantContext: $tenantContext,
            rowReader: new ImportRowReader(new EncodingDetector(), new DelimiterDetector()),
            compositeValueParser: new CompositeValueParser(),
            columnGrammar: $this->grammarWith(['pl', 'en']),
            objectTypeAttributeCodes: new InMemoryObjectTypeAttributeCodes(['sku']),
        );

        $csv = "ean;name\n;Bez EAN\n5901234123457;Z EAN\n";
        $path = $this->writeTempCsv($csv);

        try {
            $result = $service->validate(
                absolutePath: $path,
                columnMapping: ['ean' => 'ean', 'name' => 'name'],
                target: new ObjectType('product', ObjectKind::Product, ['en' => 'Product']),
                matchAttributeCode: 'ean',
            );

            self::assertSame(1, $result->errorCount, 'only the row without an EAN fails');
            $errorTypes = array_map(static fn ($e): string => $e->errorType->value, $result->errors);
            self::assertContains(ImportErrorType::MissingRequired->value, $errorTypes);
            $columns = array_map(static fn ($e): ?string => $e->columnName, $result->errors);
            self::assertContains('ean', $columns, 'the missing column named is the match attribute');
        } finally {
            @unlink($path);
        }
    }

    private function writeTempCsv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imp-validate-unit-');
        self::assertNotFalse($path);
        $renamed = $path.'.csv';
        rename($path, $renamed);
        file_put_contents($renamed, $contents);

        return $renamed;
    }

    /**
     * @param list<string> $localeCodes
     */
    private function grammarWith(array $localeCodes): \App\Import\Application\Service\ImportColumnGrammar
    {
        $scopes = $this->createStub(\App\Channel\Contracts\ScopeEnumeratorInterface::class);
        $scopes->method('localeShortCodes')->willReturn($localeCodes);
        $scopes->method('channelIdsByCode')->willReturn([]);

        return new \App\Import\Application\Service\ImportColumnGrammar($scopes);
    }
}

/**
 * @internal
 */
final class InMemoryAttributeRepository implements AttributeRepositoryInterface
{
    /**
     * @param array<string, Attribute> $byCode
     */
    public function __construct(private readonly array $byCode)
    {
    }

    public function findById(Uuid $id): ?Attribute
    {
        foreach ($this->byCode as $attribute) {
            if ($attribute->getId()->toRfc4122() === $id->toRfc4122()) {
                return $attribute;
            }
        }

        return null;
    }

    public function findByCode(string $code, Tenant $tenant): ?Attribute
    {
        return $this->byCode[$code] ?? null;
    }

    public function save(Attribute $attribute): void
    {
    }

    public function remove(Attribute $attribute): void
    {
    }

    public function filterableCodes(): array
    {
        $codes = [];
        foreach ($this->byCode as $attribute) {
            if ($attribute->isFilterable()) {
                $codes[] = $attribute->getCode();
            }
        }

        return $codes;
    }

    public function findAllByTenant(Tenant $tenant): array
    {
        return array_values($this->byCode);
    }
}

/**
 * @internal
 */
final class InMemoryCatalogObjectRepository implements CatalogObjectRepositoryInterface
{
    /**
     * @param array<string, CatalogObject> $bySku
     */
    public function __construct(private readonly array $bySku)
    {
    }

    public function findByCode(string $code, ObjectKind $kind, Tenant $tenant): ?CatalogObject
    {
        return $this->bySku[$code] ?? null;
    }

    public function findByCodeInObjectTypes(string $code, array $objectTypeIds, Tenant $tenant): ?CatalogObject
    {
        return $this->bySku[$code] ?? null;
    }

    public function findChildrenByParentIds(array $parentIdsRfc4122, Tenant $tenant): array
    {
        return [];
    }

    public function findById(Uuid $id): ?CatalogObject
    {
        return null;
    }

    public function findByIds(array $idsRfc4122): array
    {
        return [];
    }

    public function findByKind(ObjectKind $kind, Tenant $tenant): array
    {
        return [];
    }

    public function findByObjectType(ObjectType $objectType, Tenant $tenant): array
    {
        return [];
    }

    public function findRootObjectsAfter(ObjectType $objectType, Tenant $tenant, ?Uuid $afterId, int $limit): array
    {
        return [];
    }

    public function countRootObjectsByType(ObjectType $objectType, Tenant $tenant): int
    {
        return 0;
    }

    public function findRootObjectIds(ObjectType $objectType, Tenant $tenant): array
    {
        return [];
    }

    public function filterRootObjectIds(array $idsRfc4122, Tenant $tenant): array
    {
        return [];
    }

    public function findChildIdsByParentIds(array $parentIdsRfc4122, Tenant $tenant): array
    {
        return [];
    }

    public function save(CatalogObject $object): void
    {
    }

    public function remove(CatalogObject $object): void
    {
    }
}

/**
 * @internal
 */
final class InMemoryObjectTypeAttributeCodes implements ObjectTypeAttributeCodesPort
{
    /**
     * @param list<string> $attachedCodes
     */
    public function __construct(private readonly array $attachedCodes)
    {
    }

    public function forObjectType(Uuid $objectTypeId): array
    {
        return $this->attachedCodes;
    }
}
