<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Application\BuiltInSystemAttributesSeeder;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Provenance;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * UI-08.6 (#261) — `POST /api/attributes/{id}/migrate-type` smoke +
 * dry-run + destructive guard.
 */
final class MigrateAttributeTypeApiTest extends CatalogApiTestCase
{
    private Tenant $tenant;
    private Attribute $material;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        $this->tenant = $tenant;

        self::getContainer()->get(BuiltInSystemAttributesSeeder::class)->seed($tenant);

        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($tenant);

        $em = $this->em();
        $this->material = new Attribute('material', ['en' => 'Material'], AttributeType::Text);
        $em->persist($this->material);
        $em->flush();

        $productType = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $productType);

        // Three rows: two map to "stal_nierdzewna" via plan, one is unmapped.
        foreach ([
            ['SKU-MIG-001', 'stal nierdzewna'],
            ['SKU-MIG-002', 'Stal nierdz.'],
            ['SKU-MIG-003', 'Plastik'],
        ] as [$code, $value]) {
            $product = new CatalogObject($productType, $code);
            $em->persist($product);
            $em->flush();
            $payload = ['value' => $value];
            $em->persist(new ObjectValue($product, $this->material, $payload, Provenance::Manual));
        }
        $em->flush();

        $tenantContext->clear();
    }

    #[Test]
    public function dryRunReturnsAnalysisWithoutMutating(): void
    {
        $client = $this->authenticatedClient();
        $body = [
            'targetType' => 'select',
            'mappingPlan' => [
                ['from' => 'stal nierdzewna', 'to' => 'stal_nierdzewna'],
                ['from' => 'Stal nierdz.', 'to' => 'stal_nierdzewna'],
            ],
            'unmappedAction' => 'null',
            'dryRun' => true,
        ];
        $response = $client->request('POST', '/api/attributes/'.$this->material->getId()->toRfc4122().'/migrate-type', [
            'json' => $body,
        ]);

        self::assertSame(200, $response->getStatusCode());
        $payload = $response->toArray();
        self::assertTrue($payload['dryRun']);
        $analysis = $payload['analysis'];
        self::assertIsArray($analysis);
        self::assertSame('safe', $analysis['compatibility']);
        self::assertSame(3, $analysis['rowCount']);
        self::assertSame(3, $analysis['distinctValues']);
        self::assertIsArray($analysis['mappings']);
        self::assertCount(2, $analysis['mappings']);
        self::assertIsArray($analysis['unmapped']);
        self::assertCount(1, $analysis['unmapped']);

        // Type unchanged after dry-run.
        $reloaded = self::getContainer()->get(AttributeRepositoryInterface::class)
            ->findById($this->material->getId());
        self::assertNotNull($reloaded);
        self::assertSame(AttributeType::Text, $reloaded->getType());
    }

    #[Test]
    public function executeMigratesTypeAndRewritesValues(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request('POST', '/api/attributes/'.$this->material->getId()->toRfc4122().'/migrate-type', [
            'json' => [
                'targetType' => 'select',
                'mappingPlan' => [
                    ['from' => 'stal nierdzewna', 'to' => 'stal_nierdzewna'],
                    ['from' => 'Stal nierdz.', 'to' => 'stal_nierdzewna'],
                ],
                'unmappedAction' => 'null',
                'dryRun' => false,
                'backupSnapshot' => true,
            ],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $payload = $response->toArray();
        self::assertFalse($payload['dryRun']);

        // Backup row exists.
        $backupCountRaw = $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM attribute_migration_backups WHERE attribute_id = ?',
            [$this->material->getId()->toRfc4122()],
        );
        $backupCount = \is_scalar($backupCountRaw) ? (int) $backupCountRaw : 0;
        self::assertSame(1, $backupCount);

        // Type flipped on attribute row.
        $type = $this->em()->getConnection()->fetchOne(
            'SELECT type FROM attributes WHERE id = ?',
            [$this->material->getId()->toRfc4122()],
        );
        self::assertSame('select', $type);

        // Mapped values rewritten to option_code shape.
        $rows = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT value FROM object_values WHERE attribute_id = ?',
            [$this->material->getId()->toRfc4122()],
        );
        self::assertCount(3, $rows);
        $optionCodes = [];
        foreach ($rows as $row) {
            $rawValue = $row['value'];
            self::assertIsString($rawValue);
            $decoded = json_decode($rawValue, true);
            self::assertIsArray($decoded);
            self::assertArrayHasKey('option_code', $decoded);
            $optionCodes[] = $decoded['option_code'];
        }
        sort($optionCodes);
        self::assertSame([null, 'stal_nierdzewna', 'stal_nierdzewna'], $optionCodes);
    }

    #[Test]
    public function migrationRequiresForceForDestructiveTypeChange(): void
    {
        $client = $this->authenticatedClient();

        // text → boolean is REQUIRES_FORCE per the compatibility matrix.
        $response = $client->request('POST', '/api/attributes/'.$this->material->getId()->toRfc4122().'/migrate-type', [
            'json' => [
                'targetType' => 'boolean',
                'mappingPlan' => [],
                'dryRun' => false,
            ],
        ]);

        self::assertSame(409, $response->getStatusCode());
    }

    #[Test]
    public function migrationToBlockedTypeReturns422(): void
    {
        $client = $this->authenticatedClient();

        // text → asset is BLOCKED.
        $response = $client->request('POST', '/api/attributes/'.$this->material->getId()->toRfc4122().'/migrate-type', [
            'json' => [
                'targetType' => 'asset',
                'mappingPlan' => [],
                'dryRun' => false,
                'force' => true,
            ],
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function systemAttributeIsImmutable(): void
    {
        $client = $this->authenticatedClient();

        $createdBy = self::getContainer()->get(AttributeRepositoryInterface::class)
            ->findByCode('created_by', $this->tenant);
        \assert(null !== $createdBy);

        $response = $client->request('POST', '/api/attributes/'.$createdBy->getId()->toRfc4122().'/migrate-type', [
            'json' => ['targetType' => 'text', 'dryRun' => false],
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function unknownAttributeReturns404(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request('POST', '/api/attributes/'.Uuid::v7()->toRfc4122().'/migrate-type', [
            'json' => ['targetType' => 'select', 'dryRun' => true],
        ]);

        self::assertSame(404, $response->getStatusCode());
    }
}
