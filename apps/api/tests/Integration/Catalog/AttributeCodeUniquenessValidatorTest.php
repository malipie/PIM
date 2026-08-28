<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\BuiltInObjectTypeSeeder;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\AttributeGroupAttribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\CategoryAttributeGroup;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Catalog\Domain\Validator\AttributeCodeUniquenessValidator;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * ADR-014 / MOD-04 (#896) — validator coverage for attribute code uniqueness
 * within the effective ObjectType model.
 */
final class AttributeCodeUniquenessValidatorTest extends KernelTestCase
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
    }

    #[Test]
    public function unusedCodeIsAccepted(): void
    {
        $product = $this->productType();

        $conflict = $this->validator()->validate('brand_new_code', $product);

        self::assertNull($conflict);
    }

    #[Test]
    public function codePresentInBaseLayerReturnsBaseConflict(): void
    {
        $em = $this->em();
        $product = $this->productType();

        $existing = new Attribute('description', ['en' => 'Description'], AttributeType::Text);
        $em->persist($existing);
        $em->flush();
        $em->persist(new ObjectTypeAttribute($product, $existing));
        $em->flush();

        $conflict = $this->validator()->validate('description', $product);

        self::assertNotNull($conflict);
        self::assertSame('description', $conflict->code);
        self::assertSame('base', $conflict->existingLocation);
        self::assertTrue($conflict->conflictingAttributeId->equals($existing->getId()));
    }

    #[Test]
    public function codeAttachedToBaseAsTheSameAttributeIsNotAConflictWhenExcluded(): void
    {
        // Idempotent re-attach: the validator is asked "would adding this
        // attribute to base introduce a clash?" — the answer is no, because
        // the row referenced is the same Attribute being re-checked.
        $em = $this->em();
        $product = $this->productType();

        $existing = new Attribute('description', ['en' => 'Description'], AttributeType::Text);
        $em->persist($existing);
        $em->flush();
        $em->persist(new ObjectTypeAttribute($product, $existing));
        $em->flush();

        $conflict = $this->validator()->validate(
            'description',
            $product,
            excludeAttribute: $existing,
        );

        self::assertNull($conflict);
    }

    #[Test]
    public function codeDistributedViaCategoryAttributeGroupReturnsCategoryConflict(): void
    {
        $em = $this->em();
        $product = $this->productType();

        // Category C declares a group G that distributes to Product. G
        // already carries an attribute with code "color"; distributing the
        // group puts "color" into Product's effective model via the
        // category layer.
        $category = $this->makeCategory('electronics', 'electronics');

        $group = new AttributeGroup('marketing', ['en' => 'Marketing']);
        $em->persist($group);
        $em->flush();

        $color = new Attribute('color', ['en' => 'Color'], AttributeType::Text);
        $em->persist($color);
        $em->flush();
        $em->persist(new AttributeGroupAttribute($group, $color, 1));
        $em->persist(new CategoryAttributeGroup($category->getId(), $product, $group, 1));
        $em->flush();

        $conflict = $this->validator()->validate('color', $product);

        self::assertNotNull($conflict);
        self::assertSame('color', $conflict->code);
        self::assertStringStartsWith('category:', $conflict->existingLocation);
        self::assertTrue($conflict->conflictingAttributeId->equals($color->getId()));
    }

    #[Test]
    public function codePresentInDifferentObjectTypeDoesNotConflict(): void
    {
        // AC-3: same code on an unrelated ObjectType (category here) does
        // NOT conflict with the product model — the current tenant-wide DB
        // unique constraint still blocks duplicate rows in `attributes`,
        // but the validator's view of "effective ObjectType model" is
        // narrower and tolerates the same code living on a different OT
        // model. Useful once tenant-wide uniqueness is relaxed in a follow-
        // up; the contract is correct today.
        $em = $this->em();
        $category = $this->categoryType();

        $existing = new Attribute('description', ['en' => 'Description'], AttributeType::Text);
        $em->persist($existing);
        $em->flush();
        $em->persist(new ObjectTypeAttribute($category, $existing));
        $em->flush();

        $conflict = $this->validator()->validate('description', $this->productType());

        self::assertNull($conflict);
    }

    private function validator(): AttributeCodeUniquenessValidator
    {
        return self::getContainer()->get(AttributeCodeUniquenessValidator::class);
    }

    private function productType(): \App\Catalog\Domain\Entity\ObjectType
    {
        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $this->tenant);
        \assert(null !== $type);

        return $type;
    }

    private function categoryType(): \App\Catalog\Domain\Entity\ObjectType
    {
        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Category, $this->tenant);
        \assert(null !== $type);

        return $type;
    }

    private function makeCategory(string $code, string $path): CatalogObject
    {
        $em = $this->em();
        $type = $this->categoryType();
        $category = new CatalogObject($type, $code);
        $category->attachToPath($path);
        $em->persist($category);
        $em->flush();

        return $category;
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
