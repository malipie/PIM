<?php

declare(strict_types=1);

namespace App\Tests\Api\Import;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use const JSON_THROW_ON_ERROR;

/**
 * IMP-03 (#444) — wizard Step 3 dry-run.
 *
 * Anchor case spec §5.4: 247 rows OK + 33 errors. The fixture is
 * generated inline (vs. checking a binary CSV into the repo) so the
 * row counts stay deterministic against the validator's rules.
 */
final class ValidateDryRunApiTest extends CatalogApiTestCase
{
    #[Test]
    public function dryRunSplitsTwoFortySevenOkFromThirtyThreeErrors(): void
    {
        $this->seedAttributesAndDuplicates();

        $csvPath = $this->writeFixtureCsv();

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions/validate-dry-run', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode([
                            'sku' => 'sku',
                            'name' => 'name',
                            'price' => 'price',
                            'ean' => 'ean',
                        ], JSON_THROW_ON_ERROR),
                    ],
                    'files' => [
                        'file' => new UploadedFile($csvPath, 'fixture.csv', 'text/csv', null, true),
                    ],
                ],
            ]);

            self::assertResponseIsSuccessful();
            $response = $client->getResponse();
            self::assertNotNull($response);
            $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);

            // IMP2-1.3 (#1465): the 18 duplicate-SKU-in-DB rows of the spec
            // fixture are no longer validation errors — the run loop decides
            // per ImportMode (dry-run buckets arrive with #1492).
            self::assertSame(280, $body['total_rows'], 'Fixture has 269 OK + 11 errored rows (required follows Attribute::isRequired since #1467).');
            self::assertSame(269, $body['success_count']);
            self::assertSame(11, $body['error_count']);

            $topErrors = $body['top_errors'] ?? null;
            self::assertIsArray($topErrors);
            self::assertLessThanOrEqual(10, \count($topErrors));
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function skippedColumnWithADotInItsNameIsNotGrammarValidated(): void
    {
        $this->seedAttributesAndDuplicates();

        // Supplier files routinely carry columns whose names contain a dot
        // (e.g. "Imp.CodeNr"). When the operator skips such a column it must not
        // be misread as an `attribute.locale` suffix and rejected — the dry-run
        // must match the import, which already ignores skipped columns.
        $csvPath = tempnam(sys_get_temp_dir(), 'dryrun-skip-').'.csv';
        file_put_contents($csvPath, "sku;Imp.CodeNr\nSKU-1;some-supplier-code\n");

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions/validate-dry-run', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'Imp.CodeNr' => 'skip'], JSON_THROW_ON_ERROR),
                    ],
                    'files' => [
                        'file' => new UploadedFile($csvPath, 'supplier.csv', 'text/csv', null, true),
                    ],
                ],
            ]);

            self::assertResponseIsSuccessful();
            $response = $client->getResponse();
            self::assertNotNull($response);
            $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);

            self::assertSame(0, $body['error_count'], 'A skipped dotted-name column must not raise a grammar error.');
            self::assertSame(1, $body['success_count']);
            self::assertStringNotContainsString(
                'neither an active locale nor a channel code',
                json_encode($body['top_errors'] ?? [], JSON_THROW_ON_ERROR),
            );
        } finally {
            @unlink($csvPath);
        }
    }

    #[Test]
    public function unsupportedExtensionReturns400(): void
    {
        $this->seedAttributesAndDuplicates();
        $csvPath = $this->writeFixtureCsv('payload', '.xls');

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions/validate-dry-run', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => '{}',
                    ],
                    'files' => [
                        'file' => new UploadedFile($csvPath, 'fixture.xls', 'application/vnd.ms-excel', null, true),
                    ],
                ],
            ]);

            self::assertResponseStatusCodeSame(400);
        } finally {
            @unlink($csvPath);
        }
    }

    private function seedAttributesAndDuplicates(): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $product = self::getContainer()
            ->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($product instanceof ObjectType);

        $sku = new Attribute('sku', ['en' => 'SKU'], AttributeType::Text);
        $name = new Attribute('name', ['en' => 'Name'], AttributeType::Text);
        $price = new Attribute('price', ['en' => 'Price'], AttributeType::Number);
        $ean = new Attribute('ean', ['en' => 'EAN'], AttributeType::Text);
        foreach ([$sku, $name, $price, $ean] as $attribute) {
            $em->persist($attribute);
        }
        $em->persist(new ObjectTypeAttribute($product, $sku, false, 1));
        $em->persist(new ObjectTypeAttribute($product, $name, false, 2));
        $em->persist(new ObjectTypeAttribute($product, $price, false, 3));
        $em->persist(new ObjectTypeAttribute($product, $ean, false, 4));
        $em->flush();

        // 18 SKUs already in the DB to trigger DuplicateSkuInDb on the
        // matching fixture rows. Codes line up with the inline fixture
        // builder below ("EXISTING-1" … "EXISTING-18").
        for ($i = 1; $i <= 18; ++$i) {
            $existing = new CatalogObject($product, \sprintf('EXISTING-%d', $i));
            $em->persist($existing);
        }
        $em->flush();
    }

    private function writeFixtureCsv(string $contents = '', string $suffix = '.csv'): string
    {
        if ('' === $contents) {
            $contents = $this->buildFixtureCsv();
        }
        $path = tempnam(sys_get_temp_dir(), 'imp-validate-');
        \assert(false !== $path);
        $renamed = $path.$suffix;
        rename($path, $renamed);
        file_put_contents($renamed, $contents);

        return $renamed;
    }

    private function buildFixtureCsv(): string
    {
        $lines = ['sku;name;price;ean'];

        // 247 OK rows — synthetic but valid against the seeded attributes.
        for ($i = 1; $i <= 247; ++$i) {
            $lines[] = \sprintf('OK-%03d;Product %03d;%s;590%010d', $i, $i, number_format(10.0 + $i / 10, 2, '.', ''), $i);
        }

        // 18 DuplicateSkuInDb (codes match seeded EXISTING-N).
        for ($i = 1; $i <= 18; ++$i) {
            $lines[] = \sprintf('EXISTING-%d;Conflict %d;19.99;5901111111%03d', $i, $i, $i);
        }

        // 8 missing required (4 missing SKU, 4 missing name).
        for ($i = 1; $i <= 4; ++$i) {
            $lines[] = \sprintf(';Anonymous %d;9.99;5901222222%03d', $i, $i);
        }
        for ($i = 1; $i <= 4; ++$i) {
            $lines[] = \sprintf('NONAME-%d;;9.99;5901333333%03d', $i, $i);
        }

        // 7 invalid_type — non-numeric price.
        for ($i = 1; $i <= 7; ++$i) {
            $lines[] = \sprintf('PRICE-BAD-%d;Bad price %d;not-a-number;5901444444%03d', $i, $i, $i);
        }

        return implode("\n", $lines)."\n";
    }
}
