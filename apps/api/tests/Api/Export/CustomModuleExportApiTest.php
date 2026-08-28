<?php

declare(strict_types=1);

namespace App\Tests\Api\Export;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Provenance;
use App\Export\Application\Sync\SyncExportRunner;
use App\Export\Domain\Entity\ExportSession;
use App\Export\Domain\Enum\ExportEntityType;
use App\Export\Domain\Enum\ExportFormat;
use App\Export\Domain\Enum\ExportSource;
use App\Export\Domain\Enum\ExportTargetScope;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * EXR-05 (#1381) — the export pipeline generalised to any ObjectType.
 *
 * Drives the real {@see SyncExportRunner} against a custom ObjectType fixture
 * and inspects the produced file: rows + attribute values come out, the target
 * set is scoped to the requested ObjectType, and a freshly-invented attribute
 * exports with zero code change (schema dynamism — the EAV requirement).
 *
 * The content is read from the runner's output file rather than an HTTP
 * response because the sync export streams a BinaryFileResponse whose body the
 * API Platform test client does not buffer. The HTTP 200 contract for
 * custom_module is covered by {@see ExportEntityTypeApiTest}.
 */
final class CustomModuleExportApiTest extends CatalogApiTestCase
{
    #[Test]
    public function exportsCustomModuleRowsScopedToItsObjectType(): void
    {
        $tenant = $this->tenant();

        $services = $this->customObjectType($tenant, 'services');
        $horsepower = $this->attribute($tenant, 'horsepower');
        $this->object($tenant, $services, 'SRV-1', $horsepower, '150');
        $this->object($tenant, $services, 'SRV-2', $horsepower, '200');

        // A different custom ObjectType in the SAME tenant must not bleed in.
        $salons = $this->customObjectType($tenant, 'salons');
        $this->object($tenant, $salons, 'SAL-1', $horsepower, '999');

        $this->em()->flush();

        $csv = $this->runExport($tenant, $services, ['sku', 'horsepower']);

        self::assertStringContainsString('SRV-1', $csv);
        self::assertStringContainsString('150', $csv);
        self::assertStringContainsString('SRV-2', $csv);
        self::assertStringContainsString('200', $csv);
        self::assertStringNotContainsString('SAL-1', $csv);
        self::assertStringNotContainsString('999', $csv);
    }

    #[Test]
    public function exportsAFreshlyAddedAttributeWithoutCodeChange(): void
    {
        $tenant = $this->tenant();

        $collections = $this->customObjectType($tenant, 'collections');
        // `torque` exists in no seed, fixture, or hardcode — it is invented here.
        $torque = $this->attribute($tenant, 'torque');
        $this->object($tenant, $collections, 'COL-1', $torque, '250');
        $this->em()->flush();

        $csv = $this->runExport($tenant, $collections, ['sku', 'torque']);

        self::assertStringContainsString('COL-1', $csv);
        self::assertStringContainsString('250', $csv);
    }

    /**
     * @param list<string> $columns
     */
    private function runExport(Tenant $tenant, ObjectType $objectType, array $columns): string
    {
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $session = new ExportSession(
            userId: Uuid::v7(),
            source: ExportSource::CentralTab,
            format: ExportFormat::Csv,
            targetScope: ExportTargetScope::All,
            selectedColumns: $columns,
            entityType: ExportEntityType::CustomModule,
            objectTypeId: $objectType->getId(),
        );
        $session->assignTenant($tenant);

        $runner = self::getContainer()->get(SyncExportRunner::class);

        $path = tempnam(sys_get_temp_dir(), 'exr05-').'.csv';
        try {
            $runner->runToFile($session, $path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    private function tenant(): Tenant
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }

    private function customObjectType(Tenant $tenant, string $code): ObjectType
    {
        $objectType = new ObjectType($code, ObjectKind::Custom, ['pl' => ucfirst($code), 'en' => ucfirst($code)]);
        $objectType->assignTenant($tenant);
        $this->em()->persist($objectType);

        return $objectType;
    }

    private function attribute(Tenant $tenant, string $code): Attribute
    {
        $attribute = new Attribute($code, ['pl' => ucfirst($code), 'en' => ucfirst($code)], AttributeType::Text);
        $attribute->assignTenant($tenant);
        $this->em()->persist($attribute);

        return $attribute;
    }

    private function object(Tenant $tenant, ObjectType $objectType, string $code, Attribute $attribute, string $value): void
    {
        $object = new CatalogObject($objectType, $code);
        $object->assignTenant($tenant);
        $this->em()->persist($object);

        $objectValue = new ObjectValue($object, $attribute, ['value' => $value], Provenance::Manual);
        $objectValue->assignTenant($tenant);
        $this->em()->persist($objectValue);
    }
}
