<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Domain\Entity\BrandVoiceProfile;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AICG-P1-02 (#2328) — BrandVoiceProfile against real Postgres:
 * round-trip of the full column set, tenant isolation (cross-read = 0,
 * DoD), and the partial unique index guaranteeing at most one
 * is_default=true per tenant (while allowing one default per EACH
 * tenant).
 */
final class BrandVoiceProfileEntityTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function voiceProfileRoundTripsWithFullColumnSet(): void
    {
        $tenant = $this->createTenant('alpha');
        $this->activateTenantFilter($tenant);
        $em = $this->em();

        $voice = new BrandVoiceProfile(
            name: 'Ekspercki',
            tone: 'ekspercki, zwięzły',
            glossary: [['term' => 'smart TV', 'use' => 'telewizor smart']],
            bannedWords: ['tani'],
            examples: [['good' => 'Precyzyjny opis.', 'bad' => 'Super okazja!!!']],
        );
        $em->persist($voice);
        $em->flush();
        $em->clear();

        $reloaded = $em->find(BrandVoiceProfile::class, $voice->getId());
        self::assertNotNull($reloaded);
        self::assertSame('Ekspercki', $reloaded->getName());
        self::assertSame('ekspercki, zwięzły', $reloaded->getTone());
        self::assertSame([['term' => 'smart TV', 'use' => 'telewizor smart']], $reloaded->getGlossary());
        self::assertSame(['tani'], $reloaded->getBannedWords());
        self::assertSame([['good' => 'Precyzyjny opis.', 'bad' => 'Super okazja!!!']], $reloaded->getExamples());
        self::assertFalse($reloaded->isDefault());
    }

    #[Test]
    public function voiceProfilesAreIsolatedByTenantFilter(): void
    {
        $alpha = $this->createTenant('alpha');
        $beta = $this->createTenant('beta');
        $em = $this->em();

        $this->activateTenantFilter($alpha);
        $voice = new BrandVoiceProfile('Alpha voice', 'neutralny');
        $em->persist($voice);
        $em->flush();
        $em->clear();

        $this->activateTenantFilter($beta);
        self::assertNull(
            $em->find(BrandVoiceProfile::class, $voice->getId()),
            'TenantFilter must hide alpha voice profiles from beta context',
        );
        self::assertCount(0, $em->getRepository(BrandVoiceProfile::class)->findAll());

        $em->clear();
        $this->activateTenantFilter($alpha);
        self::assertNotNull($em->find(BrandVoiceProfile::class, $voice->getId()));
    }

    #[Test]
    public function secondDefaultInOneTenantIsRejectedByThePartialUniqueIndex(): void
    {
        $tenant = $this->createTenant('alpha');
        $this->activateTenantFilter($tenant);
        $em = $this->em();

        $first = new BrandVoiceProfile('First', 'neutralny');
        $first->markDefault(true);
        $em->persist($first);
        $em->flush();

        $second = new BrandVoiceProfile('Second', 'ekspercki');
        $second->markDefault(true);
        $em->persist($second);

        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }

    #[Test]
    public function eachTenantMayCarryItsOwnDefault(): void
    {
        $alpha = $this->createTenant('alpha');
        $beta = $this->createTenant('beta');
        $em = $this->em();

        $this->activateTenantFilter($alpha);
        $alphaVoice = new BrandVoiceProfile('Alpha default', 'neutralny');
        $alphaVoice->markDefault(true);
        $em->persist($alphaVoice);
        $em->flush();
        $em->clear();

        $this->activateTenantFilter($beta);
        $betaVoice = new BrandVoiceProfile('Beta default', 'swobodny');
        $betaVoice->markDefault(true);
        $em->persist($betaVoice);
        $em->flush();
        $em->clear();

        $this->activateTenantFilter($beta);
        $reloaded = $em->find(BrandVoiceProfile::class, $betaVoice->getId());
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isDefault());
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
