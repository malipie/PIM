<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Domain\Entity\ContentRecipe;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AICG-P1-01 (#2327) — ContentRecipe against real Postgres: round-trip
 * of the full column set, tenant isolation (cross-read = 0, DoD), and
 * the UNIQUE(tenant_id, code) constraint (same code allowed for two
 * tenants, rejected within one).
 */
final class ContentRecipeEntityTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function recipeRoundTripsWithFullColumnSet(): void
    {
        $tenant = $this->createTenant('alpha');
        $this->activateTenantFilter($tenant);
        $em = $this->em();

        $objectTypeId = Uuid::v7();
        $brandVoiceId = Uuid::v7();
        $recipe = new ContentRecipe(
            code: 'product_description',
            name: 'Opis produktu',
            targetAttribute: 'description',
            sourceAttributes: ['material', 'color'],
            constraints: ['format' => 'html', 'max_len' => 1200, 'seo' => ['keyword' => 'HDMI']],
            objectTypeId: $objectTypeId,
        );
        $recipe->updateAppliesTo(['channel' => 'b2c']);
        $recipe->updateToneHint('ekspercki, zwięzły');
        $recipe->attachBrandVoice($brandVoiceId);

        $em->persist($recipe);
        $em->flush();
        $em->clear();

        $reloaded = $em->find(ContentRecipe::class, $recipe->getId());
        self::assertNotNull($reloaded);
        self::assertSame('product_description', $reloaded->getCode());
        self::assertSame(['material', 'color'], $reloaded->getSourceAttributes());
        self::assertSame('html', $reloaded->getConstraints()['format']);
        self::assertSame(['channel' => 'b2c'], $reloaded->getAppliesTo());
        self::assertSame('ekspercki, zwięzły', $reloaded->getToneHint());
        self::assertTrue($objectTypeId->equals($reloaded->getObjectTypeId() ?? Uuid::v4()));
        self::assertTrue($brandVoiceId->equals($reloaded->getBrandVoiceId() ?? Uuid::v4()));
        self::assertFalse($reloaded->isBuiltIn());
    }

    #[Test]
    public function recipesAreIsolatedByTenantFilter(): void
    {
        $alpha = $this->createTenant('alpha');
        $beta = $this->createTenant('beta');
        $em = $this->em();

        $this->activateTenantFilter($alpha);
        $recipe = new ContentRecipe('alpha_recipe', 'Alpha', 'description');
        $em->persist($recipe);
        $em->flush();
        $em->clear();

        $this->activateTenantFilter($beta);
        self::assertNull(
            $em->find(ContentRecipe::class, $recipe->getId()),
            'TenantFilter must hide alpha recipes from beta context',
        );
        self::assertCount(0, $em->getRepository(ContentRecipe::class)->findAll());

        $em->clear();
        $this->activateTenantFilter($alpha);
        self::assertNotNull($em->find(ContentRecipe::class, $recipe->getId()));
    }

    #[Test]
    public function sameCodeIsAllowedAcrossTenantsButUniqueWithinOne(): void
    {
        $alpha = $this->createTenant('alpha');
        $beta = $this->createTenant('beta');
        $em = $this->em();

        $this->activateTenantFilter($alpha);
        $em->persist(new ContentRecipe('meta_seo', 'Meta SEO', 'meta_description'));
        $em->flush();
        $em->clear();

        $this->activateTenantFilter($beta);
        $em->persist(new ContentRecipe('meta_seo', 'Meta SEO', 'meta_description'));
        $em->flush();
        $em->clear();

        $this->activateTenantFilter($alpha);
        $em->persist(new ContentRecipe('meta_seo', 'Duplicate', 'meta_description'));

        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function createTenant(string $code): Tenant
    {
        $em = $this->em();
        $tenant = new Tenant($code, ucfirst($code).' Tenant');
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function activateTenantFilter(Tenant $tenant): void
    {
        $managed = $this->em()->find(Tenant::class, $tenant->getId()->toRfc4122());
        \assert($managed instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($managed);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();
    }
}
