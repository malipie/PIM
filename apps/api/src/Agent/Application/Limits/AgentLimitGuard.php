<?php

declare(strict_types=1);

namespace App\Agent\Application\Limits;

use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Domain\Entity\AgentToolCall;
use App\Agent\Domain\Exception\AgentLimitExceededException;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P1-04 (#1956) — the non-negotiable usage limits of
 * architecture 8.5, enforced on real persisted usage (agent_runs
 * accounting + agent_tool_calls trace, both tenant-scoped):
 *
 *   - 50 tool calls / hour / user        (sliding 1h window)
 *   - 500k tokens / day / user           (UTC day window)
 *   - $20 / day / tenant                 (UTC day window)
 *   - $300 / month / tenant              (UTC month window)
 *
 * (10 tool calls / run and 100k tokens / run are enforced inside the
 * loop itself, P1-03.) The kill-switch IS the window arithmetic: an
 * exceeded day budget keeps refusing until midnight UTC, the month
 * budget until the month rolls over. The org-level $1000/month cap in
 * the Anthropic Console is an independent operational hardstop
 * (documented in the runbook, P9-04), not code.
 *
 * Checked at run start (AgentRunStarter -> 429 RFC 7807) and before
 * every model call inside the loop (-> run status `error`).
 */
final readonly class AgentLimitGuard
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private int $toolCallsPerHourPerUser,
        private int $tokensPerDayPerUser,
        private float $costPerDayPerTenantUsd,
        private float $costPerMonthPerTenantUsd,
    ) {
    }

    /**
     * @throws AgentLimitExceededException
     */
    public function assertWithinLimits(Tenant $tenant, Uuid $userId): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($this->toolCallsSince($userId, $now->modify('-1 hour')) >= $this->toolCallsPerHourPerUser) {
            throw AgentLimitExceededException::toolCallsPerHour($this->toolCallsPerHourPerUser);
        }

        $dayStart = $now->setTime(0, 0);
        if ($this->userTokensSince($userId, $dayStart) >= $this->tokensPerDayPerUser) {
            throw AgentLimitExceededException::tokensPerDay($this->tokensPerDayPerUser);
        }

        if ($this->tenantCostSince($dayStart) >= $this->costPerDayPerTenantUsd) {
            throw AgentLimitExceededException::costPerDay($this->costPerDayPerTenantUsd);
        }

        $monthStart = $dayStart->modify('first day of this month');
        if ($this->tenantCostSince($monthStart) >= $this->costPerMonthPerTenantUsd) {
            throw AgentLimitExceededException::costPerMonth($this->costPerMonthPerTenantUsd);
        }
    }

    private function toolCallsSince(Uuid $userId, DateTimeImmutable $since): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(AgentToolCall::class, 'c')
            ->join('c.run', 'r')
            ->where('r.userId = :user')
            ->andWhere('c.createdAt >= :since')
            ->setParameter('user', $userId, 'uuid')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) (\is_scalar($count) ? $count : 0);
    }

    private function userTokensSince(Uuid $userId, DateTimeImmutable $since): int
    {
        $sum = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(r.tokensInput + r.tokensOutput), 0)')
            ->from(AgentRun::class, 'r')
            ->where('r.userId = :user')
            ->andWhere('r.startedAt >= :since')
            ->setParameter('user', $userId, 'uuid')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) (\is_scalar($sum) ? $sum : 0);
    }

    /**
     * Tenant scope comes from the Doctrine TenantFilter (the guard runs
     * inside a tenant-bound request/worker), so the query itself only
     * narrows by time.
     */
    private function tenantCostSince(DateTimeImmutable $since): float
    {
        $sum = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(r.costUsd), 0)')
            ->from(AgentRun::class, 'r')
            ->where('r.startedAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) (\is_scalar($sum) ? $sum : 0.0);
    }
}
