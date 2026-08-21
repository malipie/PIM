<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\BuiltInLabelAttributeSeeder;
use App\Catalog\Application\BuiltInObjectTypeSeeder;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * #2942 — `name` is what the category tree, the object summary and the
 * categories export render. A tenant without it silently loses every name
 * an operator types, because {@see ObjectAttributesUpserter} drops payload
 * keys that resolve to no attribute.
 */
final class BuiltInLabelAttributeSeederTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function seedCreatesTheNameAttributeAsOperatorEditable(): void
    {
        $tenant = $this->createTenant('demo');
        $this->builtInObjectTypeSeeder()->seed($tenant);

        $created = $this->seeder()->seed($tenant);

        self::assertSame(1, $created);

        $name = $this->attributeRepository()->findByCode('name', $tenant);
        self::assertInstanceOf(Attribute::class, $name);
        self::assertSame(AttributeType::Text, $name->getType());
        self::assertTrue($name->isLocalizable(), 'the display label is per-locale');
        // Not required: an installation that worked yesterday must not start
        // rejecting writes that omit a name. Not system: unlike the audit
        // attributes it belongs in the operator's editable library.
        self::assertFalse($name->isRequired());
        self::assertFalse($name->isSystem());
    }

    #[Test]
    public function seedPointsEveryBuiltInObjectTypeAtTheNameAttribute(): void
    {
        $tenant = $this->createTenant('demo');
        $this->builtInObjectTypeSeeder()->seed($tenant);

        $this->seeder()->seed($tenant);

        $name = $this->attributeRepository()->findByCode('name', $tenant);
        self::assertInstanceOf(Attribute::class, $name);

        foreach ([ObjectKind::Product, ObjectKind::Category, ObjectKind::Asset] as $kind) {
            $type = $this->objectTypeRepository()->findBuiltInByKind($kind, $tenant);
            self::assertNotNull($type, $kind->value);
            self::assertSame(
                $name->getId()->toRfc4122(),
                $type->getLabelAttribute()?->getId()->toRfc4122(),
                \sprintf('%s must display through `name`', $kind->value),
            );
        }
    }

    #[Test]
    public function seedIsIdempotent(): void
    {
        $tenant = $this->createTenant('demo');
        $this->builtInObjectTypeSeeder()->seed($tenant);
        $seeder = $this->seeder();

        self::assertSame(1, $seeder->seed($tenant), 'first run creates the attribute');
        self::assertSame(0, $seeder->seed($tenant), 'second run is a no-op');
    }

    #[Test]
    public function seedKeepsALabelAttributeTheTenantAlreadyChose(): void
    {
        $tenant = $this->createTenant('demo');
        $this->builtInObjectTypeSeeder()->seed($tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $em = $this->em();
        $ownChoice = new Attribute('headline', ['pl' => 'Nagłówek', 'en' => 'Headline'], AttributeType::Text);
        $em->persist($ownChoice);
        $em->flush();

        $productType = $this->objectTypeRepository()->findBuiltInByKind(ObjectKind::Product, $tenant);
        self::assertNotNull($productType);
        $productType->assignLabelAttribute($ownChoice);
        $em->flush();

        $this->seeder()->seed($tenant);

        // The seeder fills a gap; it never overrules modeling the tenant did
        // themselves. Category/Asset still get the default pointer.
        self::assertSame(
            $ownChoice->getId()->toRfc4122(),
            $productType->getLabelAttribute()?->getId()->toRfc4122(),
        );
        $categoryType = $this->objectTypeRepository()->findBuiltInByKind(ObjectKind::Category, $tenant);
        self::assertNotNull($categoryType);
        self::assertSame('name', $categoryType->getLabelAttribute()?->getCode());
    }

    private function seeder(): BuiltInLabelAttributeSeeder
    {
        return self::getContainer()->get(BuiltInLabelAttributeSeeder::class);
    }

    private function builtInObjectTypeSeeder(): BuiltInObjectTypeSeeder
    {
        return self::getContainer()->get(BuiltInObjectTypeSeeder::class);
    }

    private function attributeRepository(): AttributeRepositoryInterface
    {
        return self::getContainer()->get(AttributeRepositoryInterface::class);
    }

    private function objectTypeRepository(): ObjectTypeRepositoryInterface
    {
        return self::getContainer()->get(ObjectTypeRepositoryInterface::class);
    }

    private function createTenant(string $code): Tenant
    {
        $em = $this->em();
        $tenant = new Tenant($code, ucfirst($code).' Tenant');
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
