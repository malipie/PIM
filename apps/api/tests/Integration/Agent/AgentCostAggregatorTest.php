<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Application\Cost\AgentCostAggregator;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
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
 * AGENT-P9-02 (#1989) — the cost aggregate sums this tenant's runs for
 * the current UTC day/month, ranks today's spenders and reports the
 * progress toward the §8.5 caps; another tenant's spend never leaks in.
 */
final class AgentCostAggregatorTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function aggregatesTenantSpendAndCapProgress(): void
    {
        $em = $this->em();
        $tenant = new Tenant('alpha', 'Alpha Tenant');
        $other = new Tenant('bravo', 'Bravo Tenant');
        $em->persist($tenant);
        $em->persist($other);
        $em->flush();

        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        $userA = Uuid::v7();
        $this->seedRun($em, $userA, 1000, 500, '4.000000');
        $this->seedRun($em, $userA, 2000, 1000, '6.000000');
        $this->seedRun($em, Uuid::v7(), 100, 50, '0.500000');

        // Another tenant's expensive run must NOT count.
        self::getContainer()->get(TenantContext::class)->set($other);
        $this->seedRun($em, Uuid::v7(), 99_999, 99_999, '999.000000');

        $managed = $em->find(Tenant::class, $tenant->getId()->toRfc4122());
        \assert($managed instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($managed);

        $report = $this->aggregator()->report();

        // 4 + 6 + 0.5 = 10.5, isolated from the 999 of the other tenant.
        self::assertSame('10.500000', $report->costTodayUsd);
        self::assertSame(4650, $report->tokensToday, '1000+500+2000+1000+100+50');
        self::assertSame(3, $report->runsToday);

        // Caps come from the P1-04 knobs (day $20, month $300 by default).
        self::assertGreaterThan(0.0, $report->dayCapUsd);
        self::assertSame(round(10.5 / $report->dayCapUsd * 100, 1), $report->dayCapPct);

        // Top spender first.
        self::assertNotEmpty($report->perUserToday);
        self::assertSame($userA->toRfc4122(), $report->perUserToday[0]['user_id']);
        self::assertSame('10.000000', $report->perUserToday[0]['cost_usd']);
    }

    private function seedRun(EntityManagerInterface $em, Uuid $userId, int $inTokens, int $outTokens, string $cost): void
    {
        $current = self::getContainer()->get(TenantContext::class)->get();
        if ($current instanceof Tenant) {
            $managed = $em->find(Tenant::class, $current->getId()->toRfc4122());
            if ($managed instanceof Tenant) {
                self::getContainer()->get(TenantContext::class)->set($managed);
            }
        }
        $run = new AgentRun($userId, AgentRunSurface::Chat, 'work');
        $run->addUsage($inTokens, $outTokens, $cost);
        $em->persist($run);
        $em->flush();
    }

    private function aggregator(): AgentCostAggregator
    {
        $aggregator = self::getContainer()->get(AgentCostAggregator::class);
        self::assertInstanceOf(AgentCostAggregator::class, $aggregator);

        return $aggregator;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
