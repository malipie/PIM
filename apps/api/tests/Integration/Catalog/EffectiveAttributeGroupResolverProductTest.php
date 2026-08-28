<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\BuiltInObjectTypeSeeder;
use App\Catalog\Application\BuiltInSystemAttributesSeeder;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\AttributeGroupAttribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\CategoryAttributeGroup;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectCategoryRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Catalog\Domain\Service\EffectiveAttributeGroupResolver;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * PCAT-03 (#476) — coverage for the `kind=Product` branch in
 * {@see EffectiveAttributeGroupResolver}. Verifies that products inherit
 * attribute groups from every assigned category's full ancestor chain,
 * deduplicated across assignments.
 */
final class EffectiveAttributeGroupResolverProductTest extends KernelTestCase
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
    public function resolveReturnsObjectTypeGroupsOnlyWhenNoAssignments(): void
    {
        $product = $this->makeProduct('sku-empty');

        $groups = $this->resolver()->resolve($product);

        $codes = array_map(static fn (AttributeGroup $g): string => $g->getCode(), $groups);
        self::assertSame([], $codes);
    }

    #[Test]
    public function resolveMergesGroupsFromAssignedCategoriesIntoProduct(): void
    {
        $em = $this->em();
        $productType = $this->productType();

        $cat = $this->makeCategory('cars');
        $spec = $this->makeGroup('vehicle-spec', 'Vehicle Spec');
        $em->persist(new CategoryAttributeGroup($cat->getId(), $productType, $spec, 1));
        $em->flush();

        $product = $this->makeProduct('sku-car-1');
        $this->assign($product, [$cat->getId()], $cat->getId());

        $groups = $this->resolver()->resolve($product);

        $codes = array_map(static fn (AttributeGroup $g): string => $g->getCode(), $groups);
        self::assertSame(['vehicle-spec'], $codes);
    }

    #[Test]
    public function resolveIncludesAncestorGroupsForEachAssignedCategory(): void
    {
        $em = $this->em();
        $productType = $this->productType();

        $root = $this->makeCategory('root', 'root');
        $branch = $this->makeCategory('branch', 'root.branch', parent: $root);
        $leaf = $this->makeCategory('leaf', 'root.branch.leaf', parent: $branch);

        $rootGroup = $this->makeGroup('root-group', 'Root');
        $branchGroup = $this->makeGroup('branch-group', 'Branch');
        $leafGroup = $this->makeGroup('leaf-group', 'Leaf');

        $em->persist(new CategoryAttributeGroup($root->getId(), $productType, $rootGroup, 1));
        $em->persist(new CategoryAttributeGroup($branch->getId(), $productType, $branchGroup, 1));
        $em->persist(new CategoryAttributeGroup($leaf->getId(), $productType, $leafGroup, 1));
        $em->flush();

        $product = $this->makeProduct('sku-deep');
        // Assign the leaf only — ancestors must still contribute.
        $this->assign($product, [$leaf->getId()], $leaf->getId());

        $groups = $this->resolver()->resolve($product);
        $codes = array_map(static fn (AttributeGroup $g): string => $g->getCode(), $groups);

        self::assertContains('root-group', $codes);
        self::assertContains('branch-group', $codes);
        self::assertContains('leaf-group', $codes);
    }

    #[Test]
    public function resolveIgnoresSecondaryCategoryAssignments(): void
    {
        $em = $this->em();
        $productType = $this->productType();

        // ADR-014 / MOD-03: only the primary category contributes to the
        // attribute overlay. Sibling categories under the same root each
        // declare their own group; assigning the product to BOTH but
        // marking only the left as primary must yield the left chain
        // (shared-root + left-group) without right-group.
        $root = $this->makeCategory('shared-root', 'shared');
        $left = $this->makeCategory('left', 'shared.left', parent: $root);
        $right = $this->makeCategory('right', 'shared.right', parent: $root);

        $shared = $this->makeGroup('shared-group', 'Shared');
        $leftGroup = $this->makeGroup('left-group', 'Left');
        $rightGroup = $this->makeGroup('right-group', 'Right');

        $em->persist(new CategoryAttributeGroup($root->getId(), $productType, $shared, 1));
        $em->persist(new CategoryAttributeGroup($left->getId(), $productType, $leftGroup, 1));
        $em->persist(new CategoryAttributeGroup($right->getId(), $productType, $rightGroup, 1));
        $em->flush();

        $product = $this->makeProduct('sku-multi');
        $this->assign($product, [$left->getId(), $right->getId()], $left->getId());

        $groups = $this->resolver()->resolve($product);
        $codes = array_map(static fn (AttributeGroup $g): string => $g->getCode(), $groups);

        self::assertContains('shared-group', $codes, 'primary ancestor chain contributes');
        self::assertContains('left-group', $codes, 'primary itself contributes');
        self::assertNotContains('right-group', $codes, 'secondary categories MUST NOT contribute');
        // Dedup invariant still holds.
        self::assertSame(\count($codes), \count(array_unique($codes)));
    }

    #[Test]
    public function resolveReturnsBaseGroupsForNonCategorizableObjectType(): void
    {
        // ADR-014 / MOD-03 — Category itself has is_categorizable=false,
        // so its instance form must show only explicitly attached base
        // AttributeGroups and skip the category overlay entirely.
        $category = $this->makeCategory('telewizory', 'elektronika.tv');

        $groups = $this->resolver()->resolve($category);
        $codes = array_map(static fn (AttributeGroup $g): string => $g->getCode(), $groups);

        self::assertSame([], $codes);
    }

    #[Test]
    public function resolveSkipsOverlayWhenCategorizableButNoPrimaryAssignment(): void
    {
        $product = $this->makeProduct('sku-no-primary');

        $groups = $this->resolver()->resolve($product);
        $codes = array_map(static fn (AttributeGroup $g): string => $g->getCode(), $groups);

        // Only the base ObjectType layer applies — empty until the operator
        // attaches groups explicitly.
        self::assertSame([], $codes);
    }

    #[Test]
    public function resolveEmitsObjectTypeGroupsBeforeCategoryGroups(): void
    {
        $em = $this->em();
        $productType = $this->productType();

        $baseGroup = $this->makeGroup('base-group', 'Base');
        $em->persist(new \App\Catalog\Domain\Entity\ObjectTypeAttributeGroup($productType, $baseGroup, 1));

        $cat = $this->makeCategory('ordercat');
        $catGroup = $this->makeGroup('cat-group', 'Cat');
        $em->persist(new CategoryAttributeGroup($cat->getId(), $productType, $catGroup, 1));
        $em->flush();

        $product = $this->makeProduct('sku-order');
        $this->assign($product, [$cat->getId()], $cat->getId());

        $groups = $this->resolver()->resolve($product);
        $codes = array_map(static fn (AttributeGroup $g): string => $g->getCode(), $groups);

        $auditIdx = array_search('base-group', $codes, true);
        $catIdx = array_search('cat-group', $codes, true);
        self::assertNotFalse($auditIdx);
        self::assertNotFalse($catIdx);
        self::assertLessThan($catIdx, $auditIdx, 'ObjectType-global groups must precede category-derived groups.');
    }

    /**
     * @param list<\Symfony\Component\Uid\Uuid> $categoryIds
     */
    private function assign(CatalogObject $product, array $categoryIds, ?\Symfony\Component\Uid\Uuid $primaryId = null): void
    {
        self::getContainer()->get(ObjectCategoryRepositoryInterface::class)
            ->replaceForProduct($product, $categoryIds, $primaryId);
    }

    private function productType(): \App\Catalog\Domain\Entity\ObjectType
    {
        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)->findBuiltInByKind(ObjectKind::Product, $this->tenant);
        \assert(null !== $type);

        return $type;
    }

    private function resolver(): EffectiveAttributeGroupResolver
    {
        return self::getContainer()->get(EffectiveAttributeGroupResolver::class);
    }

    private function makeProduct(string $code): CatalogObject
    {
        $em = $this->em();
        $product = new CatalogObject($this->productType(), $code);
        $em->persist($product);
        $em->flush();

        return $product;
    }

    private function makeCategory(string $code, ?string $path = null, ?CatalogObject $parent = null): CatalogObject
    {
        $em = $this->em();
        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)->findBuiltInByKind(ObjectKind::Category, $this->tenant);
        \assert(null !== $type);
        $category = new CatalogObject($type, $code);
        $category->attachToPath($path ?? $code);
        if (null !== $parent) {
            $category->assignParent($parent);
        }
        $em->persist($category);
        $em->flush();

        return $category;
    }

    private function makeGroup(string $code, string $label): AttributeGroup
    {
        $em = $this->em();
        $group = new AttributeGroup($code, ['en' => $label]);
        $em->persist($group);
        $attribute = new Attribute($code.'_field', ['en' => $label.' field'], AttributeType::Text);
        $em->persist($attribute);
        $em->flush();
        $em->persist(new AttributeGroupAttribute($group, $attribute, 1));
        $em->flush();

        return $group;
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
