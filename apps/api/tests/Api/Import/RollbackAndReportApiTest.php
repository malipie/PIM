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
 * IMP-05 (#446) — round-trips the rollback + CSV report endpoints
 * over the same stack the wizard's results screen consumes.
 */
final class RollbackAndReportApiTest extends CatalogApiTestCase
{
    #[Test]
    public function rollbackDeletesImportedProductsAndFlipsStatus(): void
    {
        $this->seedAttributes();
        $sessionId = $this->runSmallImport(rowCount: 3);

        $client = $this->authenticatedClient();
        $client->request('POST', \sprintf('/api/import-sessions/%s/rollback', $sessionId));

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('rolled_back', $body['status']);
        self::assertSame(3, $body['deleted_objects']);

        // Re-import the same SKU should now succeed (no DuplicateSkuInDb).
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $remaining = $em->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(CatalogObject::class, 'o')
            ->where('o.importSessionId = :sessionId')
            ->setParameter('sessionId', $sessionId)
            ->getQuery()
            ->getSingleScalarResult();
        self::assertSame('0', (string) $remaining);
    }

    #[Test]
    public function rollbackRejectsExpiredWindow(): void
    {
        $this->seedAttributes();
        $sessionId = $this->runSmallImport(rowCount: 2);

        // Force the rollback window past now via raw SQL — the entity
        // exposes no setter, and we don't want to wait 24h in CI.
        $em = $this->em();
        $em->getConnection()->executeStatement(
            'UPDATE import_sessions SET rollback_until = :past WHERE id = :id',
            ['past' => '2020-01-01 00:00:00', 'id' => $sessionId],
        );
        $em->clear();

        $client = $this->authenticatedClient();
        $client->request('POST', \sprintf('/api/import-sessions/%s/rollback', $sessionId));

        self::assertResponseStatusCodeSame(409);
    }

    #[Test]
    public function reportCsvStreamsErrorRowsForTheSession(): void
    {
        $this->seedAttributes();

        // Force one error with a row missing `sku` (the only always-required
        // code since #1467 — `name` follows Attribute::isRequired now).
        $csv = "sku;name\nGOOD-1;OK\n;Bez SKU";
        $sessionId = $this->runImportWithCsv($csv);

        $client = $this->authenticatedClient();
        $client->request('GET', \sprintf('/api/import-sessions/%s/report.csv', $sessionId));

        self::assertResponseIsSuccessful();
        // IMP2-2.7 (#1483) — report is now a StreamedResponse; HttpKernelBrowser
        // captures its streamed body, so getContent() still returns the CSV.
        $body = $client->getResponse()?->getContent() ?? '';

        self::assertStringContainsString('row_number,sku,error_type,error_message,column,value', $body);
        self::assertStringContainsString('missing_required', $body);
    }

    private function runSmallImport(int $rowCount): string
    {
        $rows = ['sku;name'];
        for ($i = 1; $i <= $rowCount; ++$i) {
            $rows[] = \sprintf('RB-%d;Product %d', $i, $i);
        }

        return $this->runImportWithCsv(implode("\n", $rows)."\n");
    }

    private function runImportWithCsv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pim-rollback-');
        \assert(false !== $path);
        $renamed = $path.'.csv';
        rename($path, $renamed);
        file_put_contents($renamed, $contents);

        try {
            $client = $this->authenticatedClient();
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'name' => 'name'], JSON_THROW_ON_ERROR),
                    ],
                    'files' => [
                        'file' => new UploadedFile($renamed, 'rb.csv', 'text/csv', null, true),
                    ],
                ],
            ]);

            self::assertResponseIsSuccessful();
            $response = $client->getResponse();
            self::assertNotNull($response);
            $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            $id = $body['id'] ?? null;
            self::assertIsString($id);

            return $id;
        } finally {
            @unlink($renamed);
        }
    }

    private function seedAttributes(): void
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
        $em->persist($sku);
        $em->persist($name);
        $em->persist(new ObjectTypeAttribute($product, $sku, false, 1));
        $em->persist(new ObjectTypeAttribute($product, $name, false, 2));
        $em->flush();
    }
}
