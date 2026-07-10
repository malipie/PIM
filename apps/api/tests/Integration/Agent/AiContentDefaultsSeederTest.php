<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Application\Content\AiContentDefaultsSeeder;
use App\Agent\Domain\Entity\BrandVoiceProfile;
use App\Agent\Domain\Entity\ContentRecipe;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AICG-P1-04 (#2330) — the built-in defaults seeder: two built-in
 * recipes + one default voice per tenant, idempotent re-runs, never
 * steals an operator-chosen default, per-tenant scoping.
 */
final class AiContentDefaultsSeederTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function seedsTwoBuiltInRecipesAndADefaultVoice(): void
    {
        $tenant = $this->createTenant('alpha');

        $created = $this->seeder()->seed($tenant);

        self::assertSame(3, $created);
        $this->activateTenantFilter($tenant);
        $recipes = $this->em()->getRepository(ContentRecipe::class)->findBy(['isBuiltIn' => true]);
        self::assertCount(2, $recipes);
        $codes = array_map(static fn (ContentRecipe $r): string => $r->getCode(), $recipes);
        sort($codes);
        self::assertSame(['meta_seo', 'product_description'], $codes);

        $default = $this->em()->getRepository(BrandVoiceProfile::class)->findOneBy(['isDefault' => true]);
        self::assertNotNull($default);
        self::assertSame('ekspercki, zwięzły', $default->getTone());
    }

    #[Test]
    public function reRunIsAnIdempotentNoOp(): void
    {
        $tenant = $this->createTenant('alpha');
        $seeder = $this->seeder();

        self::assertSame(3, $seeder->seed($tenant));
        self::assertSame(0, $seeder->seed($tenant));

        $this->activateTenantFilter($tenant);
        self::assertCount(2, $this->em()->getRepository(ContentRecipe::class)->findAll());
        self::assertCount(1, $this->em()->getRepository(BrandVoiceProfile::class)->findAll());
    }

    #[Test]
    public function neverStealsAnExistingOperatorDefaultVoice(): void
    {
        $tenant = $this->createTenant('alpha');
        $this->activateTenantFilter($tenant);
        $em = $this->em();

        $operatorVoice = new BrandVoiceProfile('Głos operatora', 'swobodny');
        $operatorVoice->markDefault(true);
        $em->persist($operatorVoice);
        $em->flush();

        // Recipes still seed (2), the voice does not (operator default wins).
        self::assertSame(2, $this->seeder()->seed($tenant));

        $default = $em->getRepository(BrandVoiceProfile::class)->findOneBy(['isDefault' => true]);
        self::assertNotNull($default);
        self::assertSame('Głos operatora', $default->getName());
        self::assertCount(1, $em->getRepository(BrandVoiceProfile::class)->findAll());
    }

    #[Test]
    public function eachTenantGetsItsOwnDefaults(): void
    {
        $alpha = $this->createTenant('alpha');
        $beta = $this->createTenant('beta');
        $seeder = $this->seeder();

        self::assertSame(3, $seeder->seed($alpha));
        self::assertSame(3, $seeder->seed($beta));

        $this->activateTenantFilter($beta);
        self::assertCount(2, $this->em()->getRepository(ContentRecipe::class)->findAll());
        self::assertCount(1, $this->em()->getRepository(BrandVoiceProfile::class)->findAll());
    }

    private function seeder(): AiContentDefaultsSeeder
    {
        return self::getContainer()->get(AiContentDefaultsSeeder::class);
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
