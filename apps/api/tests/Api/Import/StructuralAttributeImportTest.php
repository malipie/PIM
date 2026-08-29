<?php

declare(strict_types=1);

namespace App\Tests\Api\Import;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\AttributeOptionRepositoryInterface;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeAttributeRepositoryInterface;
use App\Channel\Domain\Entity\Locale;
use App\Channel\Domain\Entity\TenantLocale;
use App\Import\Application\Service\Structural\AttributeImportCreator;
use App\Import\Application\Service\Structural\StructuralImportRowResult;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use App\Tests\Support\SqlQueryCounter;
use PHPUnit\Framework\Attributes\Test;

use const JSON_THROW_ON_ERROR;

/**
 * Structural attribute import — the mirror of the `attributes_groups` export.
 * Exercises {@see AttributeImportCreator} directly (no HTTP/MinIO) so the core
 * upsert + assignment behaviour is covered independently of auth/staging.
 */
final class StructuralAttributeImportTest extends CatalogApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Active tenant locales pl/en — the column grammar (IMP2-1.6) validates
        // `label.pl` / `label.en` suffixes against this registry; the
        // schema-built test DB ships no locale seeds.
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
    public function createsNewAttributeAndAssignsObjectTypeAndGroup(): void
    {
        $tenant = $this->tenant();
        $module = $this->customObjectType($tenant, 'widgets');
        $this->group($tenant, 'marketing');

        $result = $this->creator()->create(2, [
            'code' => 'tagline',
            'type' => 'text',
            'label.pl' => 'Hasło',
            'label.en' => 'Tagline',
            'is_localizable' => 'true',
            'groups' => 'marketing',
            'object_types' => 'widgets',
        ], $tenant);

        self::assertSame(StructuralImportRowResult::OUTCOME_CREATED, $result->outcome);

        $attribute = $this->attributes()->findByCode('tagline', $tenant);
        self::assertInstanceOf(Attribute::class, $attribute);
        self::assertSame(AttributeType::Text, $attribute->getType());
        self::assertSame(['pl' => 'Hasło', 'en' => 'Tagline'], $attribute->getLabel());
        self::assertTrue($attribute->isLocalizable());

        // Assigned to the module and the group.
        self::assertNotNull($this->junctions()->findOne($module, $attribute));
        self::assertContains('marketing', $this->groupCodesOf($attribute));
    }

    #[Test]
    public function reimportIsIdempotentAndReportsUpdate(): void
    {
        $tenant = $this->tenant();
        $module = $this->customObjectType($tenant, 'widgets');
        $this->group($tenant, 'reimport_group');

        $cells = [
            'code' => 'sku_ref',
            'type' => 'text',
            'label.en' => 'SKU',
            'groups' => 'reimport_group|reimport_group',
            'object_types' => 'widgets',
        ];
        $first = $this->creator()->create(2, $cells, $tenant);
        self::assertSame(StructuralImportRowResult::OUTCOME_CREATED, $first->outcome);

        $second = $this->creator()->create(2, $cells, $tenant);
        self::assertSame(StructuralImportRowResult::OUTCOME_UPDATED, $second->outcome);

        $attribute = $this->attributes()->findByCode('sku_ref', $tenant);
        self::assertInstanceOf(Attribute::class, $attribute);
        // No duplicate junction on re-import.
        self::assertCount(1, $this->junctions()->findByAttribute($attribute));
        self::assertSame(['reimport_group'], $this->groupCodesOf($attribute));
    }

    #[Test]
    public function unknownGroupWarnsWhileKnownGroupIsStillAttached(): void
    {
        $tenant = $this->tenant();
        $this->group($tenant, 'known_group');
        $otherTenant = new Tenant('structural-other', 'Structural Other Tenant');
        $this->em()->persist($otherTenant);
        $this->em()->flush();
        $this->group($otherTenant, 'does_not_exist');

        $result = $this->creator()->create(2, [
            'code' => 'group_warning',
            'type' => 'text',
            'groups' => 'known_group|does_not_exist',
        ], $tenant);

        self::assertSame(StructuralImportRowResult::OUTCOME_CREATED, $result->outcome);
        $attribute = $this->attributes()->findByCode('group_warning', $tenant);
        self::assertInstanceOf(Attribute::class, $attribute);
        self::assertSame(['known_group'], $this->groupCodesOf($attribute));
        self::assertNotEmpty($result->logs);
        self::assertStringContainsString('does_not_exist', $result->logs[0]['message']);
    }

    #[Test]
    public function groupLookupSelectCountIsConstantForOneAndOneHundredCodes(): void
    {
        $tenant = $this->tenant();
        $codes = [];
        for ($index = 1; $index <= 100; ++$index) {
            $code = \sprintf('prefetch_group_%03d', $index);
            $group = new AttributeGroup($code, ['en' => $code]);
            $group->assignTenant($tenant);
            $this->em()->persist($group);
            $codes[] = $code;
        }
        $this->em()->flush();

        $oneCodeQueries = $this->groupAssociationLookupQueries($tenant, 'prefetch_one', [$codes[0]]);
        $hundredCodeQueries = $this->groupAssociationLookupQueries($tenant, 'prefetch_hundred', $codes);

        self::assertSame(2, $oneCodeQueries, 'one group needs one group SELECT and one junction SELECT');
        self::assertSame(
            $oneCodeQueries,
            $hundredCodeQueries,
            'group and junction lookup SELECTs must not scale with the number of imported group codes',
        );
    }

    #[Test]
    public function unknownObjectTypeWarnsButStillImportsTheRow(): void
    {
        $tenant = $this->tenant();

        $result = $this->creator()->create(2, [
            'code' => 'weight',
            'type' => 'number',
            'object_types' => 'does_not_exist',
        ], $tenant);

        self::assertSame(StructuralImportRowResult::OUTCOME_CREATED, $result->outcome);
        self::assertInstanceOf(Attribute::class, $this->attributes()->findByCode('weight', $tenant));
        self::assertNotEmpty($result->logs, 'unknown object type should emit a warning');
        self::assertStringContainsString('does_not_exist', $result->logs[0]['message']);
    }

    #[Test]
    public function invalidTypeFailsTheRowWithoutCreatingAnAttribute(): void
    {
        $tenant = $this->tenant();

        $result = $this->creator()->create(2, ['code' => 'broken', 'type' => 'not_a_type'], $tenant);

        self::assertSame(StructuralImportRowResult::OUTCOME_ERROR, $result->outcome);
        self::assertNull($this->attributes()->findByCode('broken', $tenant));
    }

    #[Test]
    public function selectAttributeImportsItsOptions(): void
    {
        $tenant = $this->tenant();

        $result = $this->creator()->create(2, [
            'code' => 'color',
            'type' => 'select',
            'label.en' => 'Color',
            'options' => json_encode([
                ['code' => 'red', 'label' => ['en' => 'Red']],
                ['code' => 'blue', 'label' => ['en' => 'Blue']],
            ], JSON_THROW_ON_ERROR),
        ], $tenant);

        self::assertSame(StructuralImportRowResult::OUTCOME_CREATED, $result->outcome);
        $attribute = $this->attributes()->findByCode('color', $tenant);
        self::assertInstanceOf(Attribute::class, $attribute);
        $codes = array_map(static fn ($o) => $o->getCode(), $this->options()->findByAttribute($attribute));
        self::assertContains('red', $codes);
        self::assertContains('blue', $codes);
    }

    private function creator(): AttributeImportCreator
    {
        return self::getContainer()->get(AttributeImportCreator::class);
    }

    private function attributes(): AttributeRepositoryInterface
    {
        return self::getContainer()->get(AttributeRepositoryInterface::class);
    }

    private function options(): AttributeOptionRepositoryInterface
    {
        return self::getContainer()->get(AttributeOptionRepositoryInterface::class);
    }

    private function junctions(): ObjectTypeAttributeRepositoryInterface
    {
        return self::getContainer()->get(ObjectTypeAttributeRepositoryInterface::class);
    }

    /**
     * @return list<string>
     */
    private function groupCodesOf(Attribute $attribute): array
    {
        /** @var list<string> $codes */
        $codes = $this->em()->createQuery(
            'SELECT g.code FROM '.\App\Catalog\Domain\Entity\AttributeGroupAttribute::class.' j JOIN j.attributeGroup g WHERE j.attribute = :a',
        )->setParameter('a', $attribute)->getSingleColumnResult();

        return $codes;
    }

    /**
     * @param list<string> $groupCodes
     */
    private function groupAssociationLookupQueries(Tenant $tenant, string $attributeCode, array $groupCodes): int
    {
        $counter = self::getContainer()->get(SqlQueryCounter::class);
        self::assertInstanceOf(SqlQueryCounter::class, $counter);
        $counter->start();

        try {
            $result = $this->creator()->create(2, [
                'code' => $attributeCode,
                'type' => 'text',
                'groups' => implode('|', $groupCodes),
            ], $tenant);
            self::assertSame(StructuralImportRowResult::OUTCOME_CREATED, $result->outcome);

            return $counter->countMatching('/\bFROM\s+"?attribute_group(?:s|_attributes)"?\b/i');
        } finally {
            $counter->stop();
        }
    }

    private function customObjectType(Tenant $tenant, string $code): ObjectType
    {
        $module = new ObjectType($code, ObjectKind::Custom, ['pl' => $code, 'en' => $code]);
        $module->assignTenant($tenant);
        $this->em()->persist($module);
        $this->em()->flush();

        return $module;
    }

    private function group(Tenant $tenant, string $code): AttributeGroup
    {
        $group = new AttributeGroup($code, ['pl' => $code, 'en' => $code]);
        $group->assignTenant($tenant);
        $this->em()->persist($group);
        $this->em()->flush();

        return $group;
    }

    private function tenant(): Tenant
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        return $tenant;
    }
}
