<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\AttributesIndexedRebuilder;
use App\Catalog\Application\BuiltInObjectTypeSeeder;
use App\Catalog\Application\BuiltInSystemAttributesSeeder;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\AttributeGroupAttribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectTypeAttributeGroup;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * ADR-014 / MOD-09 (#901) — orphaned values (attribute codes with stored
 * values outside the current effective model) MUST NOT contribute to
 * completeness, and the form-schema MUST NOT render them.
 */
final class OrphanedValuesTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $em = $this->em();
        $this->tenant = new Tenant('demo', 'Demo');
        $em->persist($this->tenant);
        $em->flush();
        $this->tenantContext()->set($this->tenant);

        self::getContainer()->get(BuiltInObjectTypeSeeder::class)->seed($this->tenant);
        self::getContainer()->get(BuiltInSystemAttributesSeeder::class)->seed($this->tenant);
    }

    #[Test]
    public function orphanedValueDoesNotCountTowardsCompleteness(): void
    {
        $em = $this->em();
        $productType = $this->productType();

        $this->attachEffectiveAttribute($productType, 'visible_orphan_guard');

        // Effective model contains an unrelated explicit attribute.
        // We declare a `required` list with two codes:
        //   - `sku`         — NOT in effective model (orphaned requirement)
        //   - `updated_at`  — NOT in effective model unless explicitly grouped
        // MOD-09 filter projects required onto the effective set; no
        // requirements remain, so completeness = 100%.
        $productType->updateCompletenessRules(['required' => ['sku', 'updated_at']]);

        $product = new CatalogObject($productType, 'SKU-ORPH-1');
        $em->persist($product);
        $em->flush();

        // Persist an updated_at value anyway; orphaned stored values must not
        // influence completeness until the attribute becomes effective.
        $updatedAtAttribute = $em->getRepository(Attribute::class)
            ->findOneBy(['code' => 'updated_at']);
        \assert(null !== $updatedAtAttribute);
        $value = new ObjectValue($product, $updatedAtAttribute, ['value' => '2026-05-24T00:00:00+00:00']);
        $em->persist($value);
        $em->flush();

        $rebuilder = self::getContainer()->get(AttributesIndexedRebuilder::class);
        $rebuilder->rebuild($product);

        // Both required codes were filtered out as orphaned → 100.
        $completeness = $product->getCompleteness();
        self::assertSame(100, $completeness['global']);
    }

    #[Test]
    public function attributeNotInEffectiveModelDoesNotPenaliseCompleteness(): void
    {
        $em = $this->em();
        $productType = $this->productType();

        $this->attachEffectiveAttribute($productType, 'visible_required_guard');

        // Two required codes, both ABSENT from the non-empty effective model.
        // MOD-09 filter projects required to empty set →
        // completeness = 100% (rather than 0%).
        $productType->updateCompletenessRules(['required' => ['ghost_a', 'ghost_b']]);

        $product = new CatalogObject($productType, 'SKU-ORPH-2');
        $em->persist($product);
        $em->flush();

        self::getContainer()->get(AttributesIndexedRebuilder::class)->rebuild($product);

        self::assertSame(100, $product->getCompleteness()['global']);
    }

    #[Test]
    public function completenessRespectsAttributesAttachedDirectlyToObjectType(): void
    {
        $em = $this->em();
        $productType = $this->productType();

        // Attach a custom AttributeGroup carrying `sku`. After attachment
        // `sku` lives in the effective model so a missing value pulls
        // completeness down.
        $group = new AttributeGroup('identification', ['en' => 'Identification']);
        $em->persist($group);
        $sku = new Attribute('sku', ['en' => 'SKU'], AttributeType::Text);
        $em->persist($sku);
        $em->flush();
        $em->persist(new AttributeGroupAttribute($group, $sku, 1));
        $em->persist(new ObjectTypeAttributeGroup($productType, $group, position: 1));
        $em->flush();

        $productType->updateCompletenessRules(['required' => ['sku']]);

        $product = new CatalogObject($productType, 'SKU-EFFECTIVE');
        $em->persist($product);
        $em->flush();

        self::getContainer()->get(AttributesIndexedRebuilder::class)->rebuild($product);

        // sku is in effective model AND required AND missing → 0%
        self::assertSame(0, $product->getCompleteness()['global']);
    }

    private function productType(): \App\Catalog\Domain\Entity\ObjectType
    {
        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $this->tenant);
        \assert(null !== $type);

        return $type;
    }

    private function attachEffectiveAttribute(\App\Catalog\Domain\Entity\ObjectType $productType, string $code): void
    {
        $em = $this->em();
        $group = new AttributeGroup($code.'_group', ['en' => 'Visible guard']);
        $attribute = new Attribute($code, ['en' => 'Visible guard'], AttributeType::Text);
        $em->persist($group);
        $em->persist($attribute);
        $em->flush();
        $em->persist(new AttributeGroupAttribute($group, $attribute, 1));
        $em->persist(new ObjectTypeAttributeGroup($productType, $group, position: 1));
        $em->flush();
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
