<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\BuiltInObjectTypeSeeder;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class BuiltInObjectTypeSeederTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function seedCreatesThreeBuiltInObjectTypesForTenant(): void
    {
        // ADR-014 / MOD-10 (#902) — Brand was demoted from built-in to
        // tenant-territory. The seeder emits exactly Product / Category /
        // Asset; Brand is no longer in the DEFINITIONS map.
        $tenant = $this->createTenant('demo');

        $created = $this->seeder()->seed($tenant);

        self::assertSame(3, $created);
        $repo = $this->repository();
        foreach ([ObjectKind::Product, ObjectKind::Category, ObjectKind::Asset] as $kind) {
            $type = $repo->findBuiltInByKind($kind, $tenant);
            self::assertNotNull($type, $kind->value);
            self::assertTrue($type->isBuiltIn());
            self::assertTrue($type->isCodeImmutable(), $kind->value);
            self::assertFalse($type->isDeletable(), $kind->value);
            self::assertNotNull($type->getIcon(), $kind->value);
            self::assertNotNull($type->getColor(), $kind->value);
            self::assertSame($kind, $type->getKind());
        }
    }

    #[Test]
    public function isCategorizableFlagIsTrueOnlyForProduct(): void
    {
        $tenant = $this->createTenant('demo');

        $this->seeder()->seed($tenant);

        $repo = $this->repository();
        $product = $repo->findBuiltInByKind(ObjectKind::Product, $tenant);
        $category = $repo->findBuiltInByKind(ObjectKind::Category, $tenant);
        $asset = $repo->findBuiltInByKind(ObjectKind::Asset, $tenant);

        self::assertNotNull($product);
        self::assertNotNull($category);
        self::assertNotNull($asset);

        self::assertTrue($product->isCategorizable(), 'Product is the only built-in that opts into primary-category overlay');
        self::assertFalse($category->isCategorizable(), 'Category itself is not categorized — only base attributes apply');
        self::assertFalse($asset->isCategorizable(), 'Asset has its own DAM workflow, not category-driven');
    }

    #[Test]
    public function seedIsIdempotent(): void
    {
        $tenant = $this->createTenant('demo');
        $seeder = $this->seeder();

        self::assertSame(3, $seeder->seed($tenant));
        self::assertSame(0, $seeder->seed($tenant), 'second run should be a no-op');
    }

    #[Test]
    public function seedIsScopedPerTenant(): void
    {
        $alpha = $this->createTenant('alpha');
        $bravo = $this->createTenant('bravo');

        $seeder = $this->seeder();
        $seeder->seed($alpha);
        $seeder->seed($bravo);

        $repo = $this->repository();
        $alphaProduct = $repo->findBuiltInByKind(ObjectKind::Product, $alpha);
        $bravoProduct = $repo->findBuiltInByKind(ObjectKind::Product, $bravo);
        self::assertNotNull($alphaProduct);
        self::assertNotNull($bravoProduct);
        // Each tenant carries its own independent rows.
        self::assertNotSame(
            $alphaProduct->getId(),
            $bravoProduct->getId(),
        );
    }

    private function seeder(): BuiltInObjectTypeSeeder
    {
        return self::getContainer()->get(BuiltInObjectTypeSeeder::class);
    }

    private function repository(): ObjectTypeRepositoryInterface
    {
        return self::getContainer()->get(ObjectTypeRepositoryInterface::class);
    }

    private function createTenant(string $code): Tenant
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $tenant = new Tenant($code, ucfirst($code).' Tenant');
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /**
     * #2875 — the tenant may already own a CUSTOM type under a built-in code.
     * `(tenant_id, code)` is unique and the idempotency check looks for a
     * built-in of that KIND, so it never saw the collision: the repair
     * command died with SQLSTATE 23505 on production, halfway through a sweep.
     *
     * Skipping is the deliberate choice. Adopting the row — flipping it to
     * built-in, undeletable, code-locked — would take the tenant's own model
     * away from them without asking.
     */
    #[Test]
    public function aCustomTypeHoldingABuiltInCodeIsSkippedNotAdopted(): void
    {
        $tenant = $this->createTenant('demo');
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $mine = new ObjectType('product', ObjectKind::Custom, ['pl' => 'Mój produkt']);
        // TenantAssignmentListener stamps this on prePersist in a request;
        // a kernel test persists directly, so bind it here.
        $mine->assignTenant($tenant);
        $em->persist($mine);
        $em->flush();

        $created = $this->seeder()->seed($tenant);

        // Category + Asset only — `product` was taken.
        self::assertSame(2, $created);

        $repo = $this->repository();
        self::assertNull($repo->findBuiltInByKind(ObjectKind::Product, $tenant));

        $stillMine = $repo->findByCode('product', $tenant);
        self::assertNotNull($stillMine);
        self::assertFalse($stillMine->isBuiltIn(), 'the tenant keeps its own type');
        self::assertSame(ObjectKind::Custom, $stillMine->getKind());
    }

    #[Test]
    public function builtInCodesAreExposedForCallersToCheckCompleteness(): void
    {
        self::assertSame(['product', 'category', 'asset'], BuiltInObjectTypeSeeder::builtInCodes());
    }
}
