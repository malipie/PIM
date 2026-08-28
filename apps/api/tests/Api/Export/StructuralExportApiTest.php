<?php

declare(strict_types=1);

namespace App\Tests\Api\Export;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\Entity\ObjectTypeAttributeGroup;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Channel\Domain\Entity\Locale;
use App\Channel\Domain\Entity\TenantLocale;
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
 * EXR-06 (#1382) — structural exporters (module_schema / attributes_groups /
 * categories) routed through the builder registry.
 */
final class StructuralExportApiTest extends CatalogApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Active locales pl/en so the structural builders fan out label/help/
        // description columns. The short code (`pl`) — not the full `pl_PL` —
        // is what the JSONB labels are keyed by and what the import grammar
        // expects, so the export columns must use it for a clean round-trip.
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);
        foreach ([['pl_PL', 'Polski', 'pl'], ['en_US', 'English', 'en']] as [$code, $label, $lang]) {
            $locale = new Locale($code, $label, null, $lang);
            $this->em()->persist($locale);
            $this->em()->persist(new TenantLocale($locale));
        }
        $this->em()->flush();
    }

    #[Test]
    public function moduleSchemaExportListsObjectTypes(): void
    {
        $tenant = $this->tenant();
        $custom = new ObjectType('gadgets', ObjectKind::Custom, ['pl' => 'Gadżety', 'en' => 'Gadgets']);
        $custom->assignTenant($tenant);
        $this->em()->persist($custom);
        $this->em()->flush();

        $csv = $this->runStructural($tenant, ExportEntityType::ModuleSchema);

        self::assertStringContainsString('object_type_code', $csv);
        self::assertStringContainsString('gadgets', $csv);
    }

    #[Test]
    public function attributesExportIncludesAFreshlyAddedAttribute(): void
    {
        $tenant = $this->tenant();
        $attribute = new Attribute('horsepower', ['pl' => 'Moc', 'en' => 'Horsepower'], AttributeType::Text);
        $attribute->assignTenant($tenant);
        $this->em()->persist($attribute);
        $this->em()->flush();

        $csv = $this->runStructural($tenant, ExportEntityType::AttributesGroups);

        self::assertStringContainsString('horsepower', $csv);
    }

    #[Test]
    public function attributesExportListsAssignedObjectTypes(): void
    {
        $tenant = $this->tenant();
        $module = new ObjectType('gizmos', ObjectKind::Custom, ['pl' => 'Gizma', 'en' => 'Gizmos']);
        $module->assignTenant($tenant);
        $attribute = new Attribute('torque', ['pl' => 'Moment', 'en' => 'Torque'], AttributeType::Text);
        $attribute->assignTenant($tenant);
        $this->em()->persist($module);
        $this->em()->persist($attribute);
        $this->em()->persist(new ObjectTypeAttribute($module, $attribute));
        $this->em()->flush();

        $csv = $this->runStructural($tenant, ExportEntityType::AttributesGroups);

        // Header is present and the attribute row carries its ObjectType code.
        self::assertStringContainsString('object_types', $csv);
        self::assertMatchesRegularExpression('/torque[^\n]*gizmos/', $csv);
    }

    #[Test]
    public function attributeGroupsExportListsGroupDefinitionsWithObjectTypes(): void
    {
        $tenant = $this->tenant();
        $module = new ObjectType('widgets', ObjectKind::Custom, ['pl' => 'Widżety', 'en' => 'Widgets']);
        $module->assignTenant($tenant);
        $group = new AttributeGroup(
            'marketing',
            ['pl' => 'Marketing PL', 'en' => 'Marketing EN'],
            0,
            null,
            ['pl' => 'Treści marketingowe', 'en' => 'Marketing content'],
            'megaphone',
        );
        $group->assignTenant($tenant);
        $this->em()->persist($module);
        $this->em()->persist($group);
        $this->em()->persist(new ObjectTypeAttributeGroup($module, $group));
        $this->em()->flush();

        $csv = $this->runStructural($tenant, ExportEntityType::AttributeGroups);

        self::assertStringContainsString('marketing', $csv);
        self::assertStringContainsString('megaphone', $csv);
        // Localised name exports under the SHORT locale code (`label.pl`), not
        // the full `label.pl_PL`, and carries the actual value — the bug that
        // made the name column come out empty.
        self::assertStringContainsString('label.pl', $csv);
        self::assertStringNotContainsString('label.pl_PL', $csv);
        self::assertStringContainsString('Marketing PL', $csv);
        // The group row carries its attached ObjectType code in object_types.
        self::assertMatchesRegularExpression('/marketing[^\n]*widgets/', $csv);
    }

    #[Test]
    public function categoriesExportListsCategoryObjects(): void
    {
        $tenant = $this->tenant();
        $categoryType = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Category, $tenant);
        \assert($categoryType instanceof ObjectType);

        $category = new CatalogObject($categoryType, 'CAT-EXPORT');
        $category->assignTenant($tenant);
        $this->em()->persist($category);
        $this->em()->flush();

        $csv = $this->runStructural($tenant, ExportEntityType::Categories);

        self::assertStringContainsString('CAT-EXPORT', $csv);
        self::assertStringContainsString('parent_code', $csv);
    }

    #[Test]
    public function moduleSchemaSyncExportReturns200(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request('POST', '/api/products/export', [
            'json' => [
                'entity_type' => 'module_schema',
                'target_scope' => 'all',
                'format' => 'csv',
            ],
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function preflightCountsStructuralRows(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request('POST', '/api/exports/preflight', [
            'json' => [
                'entity_type' => 'attributes_groups',
                'target_scope' => 'all',
            ],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $body = $response->toArray(false);
        self::assertIsInt($body['count']);
        self::assertContains($body['mode'], ['sync', 'async']);
    }

    private function runStructural(Tenant $tenant, ExportEntityType $type): string
    {
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $session = new ExportSession(
            userId: Uuid::v7(),
            source: ExportSource::CentralTab,
            format: ExportFormat::Csv,
            targetScope: ExportTargetScope::All,
            selectedColumns: [],
            entityType: $type,
        );
        $session->assignTenant($tenant);

        $runner = self::getContainer()->get(SyncExportRunner::class);
        $path = tempnam(sys_get_temp_dir(), 'exr06-').'.csv';
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
}
