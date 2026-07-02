<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Application\Limits\AgentLimitGuard;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Domain\Entity\AgentToolCall;
use App\Agent\Domain\Exception\AgentLimitExceededException;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AGENT-P1-04 (#1956, SEC failing-test-first) — every 8.5 budget blocks
 * when exceeded and passes below the cap; the kill-switch is the window
 * arithmetic (a blown day budget refuses until midnight UTC by
 * construction of the day window).
 */
final class AgentLimitGuardTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function toolCallsPerHourBlocksAtTheCap(): void
    {
        [$tenant, $em] = $this->fixture();
        $userId = Uuid::v7();

        $run = new AgentRun($userId, AgentRunSurface::Chat, 'seed');
        $run->markCancelled();
        $em->persist($run);
        for ($i = 0; $i < 3; ++$i) {
            $em->persist(new AgentToolCall($run, 'ping', 'read', []));
        }
        $em->flush();

        $guard = $this->guard($em, toolCallsPerHour: 3);
        $this->expectException(AgentLimitExceededException::class);
        $this->expectExceptionMessageMatches('/tool calls per hour/');
        $guard->assertWithinLimits($tenant, $userId);
    }

    #[Test]
    public function tokensPerDayBlocksAtTheCapForThatUserOnly(): void
    {
        [$tenant, $em] = $this->fixture();
        $userId = Uuid::v7();
        $otherUser = Uuid::v7();

        $run = new AgentRun($userId, AgentRunSurface::Chat, 'seed');
        $run->addUsage(400_000, 100_000, '1.000000');
        $run->markCancelled();
        $em->persist($run);
        $em->flush();

        $guard = $this->guard($em, tokensPerDay: 500_000);

        // The other user is unaffected (per-user budget).
        $guard->assertWithinLimits($tenant, $otherUser);

        $this->expectException(AgentLimitExceededException::class);
        $this->expectExceptionMessageMatches('/tokens per day/');
        $guard->assertWithinLimits($tenant, $userId);
    }

    #[Test]
    public function dailyTenantCostBlocksAtTheCap(): void
    {
        [$tenant, $em] = $this->fixture();

        $run = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'seed');
        $run->addUsage(1000, 1000, '20.000000');
        $run->markCancelled();
        $em->persist($run);
        $em->flush();

        $guard = $this->guard($em, costPerDay: 20.0);
        $this->expectException(AgentLimitExceededException::class);
        $this->expectExceptionMessageMatches('/per day per tenant/');
        $guard->assertWithinLimits($tenant, Uuid::v7());
    }

    #[Test]
    public function monthlyTenantCostBlocksAtTheCap(): void
    {
        [$tenant, $em] = $this->fixture();

        $run = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'seed');
        $run->addUsage(1000, 1000, '300.000000');
        $run->markCancelled();
        $em->persist($run);
        $em->flush();

        // Daily cap high enough that only the monthly one trips.
        $guard = $this->guard($em, costPerDay: 1_000.0, costPerMonth: 300.0);
        $this->expectException(AgentLimitExceededException::class);
        $this->expectExceptionMessageMatches('/per month/');
        $guard->assertWithinLimits($tenant, Uuid::v7());
    }

    #[Test]
    public function belowEveryCapPasses(): void
    {
        [$tenant, $em] = $this->fixture();

        $run = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'seed');
        $run->addUsage(1000, 500, '0.010000');
        $run->markCancelled();
        $em->persist($run);
        $em->persist(new AgentToolCall($run, 'ping', 'read', []));
        $em->flush();

        $this->guard($em)->assertWithinLimits($tenant, Uuid::v7());
        $this->addToAssertionCount(1);
    }

    /**
     * @return array{0: Tenant, 1: EntityManagerInterface}
     */
    private function fixture(): array
    {
        $tenant = new Tenant('alpha', 'Alpha Tenant');
        $em = $this->em();
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        return [$tenant, $em];
    }

    private function guard(
        EntityManagerInterface $em,
        int $toolCallsPerHour = 50,
        int $tokensPerDay = 500_000,
        float $costPerDay = 20.0,
        float $costPerMonth = 300.0,
    ): AgentLimitGuard {
        return new AgentLimitGuard($em, $toolCallsPerHour, $tokensPerDay, $costPerDay, $costPerMonth);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
