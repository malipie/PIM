<?php

declare(strict_types=1);

namespace App\Agent\Application\Run;

use App\Agent\Application\AgentFeatureGuard;
use App\Agent\Application\Limits\AgentLimitGuard;
use App\Agent\Domain\AgentRunStatus;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Domain\Exception\ActiveRunConflictException;
use App\Shared\Domain\Tenant;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P1-03 (#1955) — the single entry point that starts a run:
 * feature guard -> persist AgentRun (a partial unique index on active
 * statuses makes "1 active run per user" race-proof at the DB level)
 * -> dispatch the async loop message.
 */
final readonly class AgentRunStarter
{
    public function __construct(
        private AgentFeatureGuard $guard,
        private AgentLimitGuard $limits,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @param array<string, mixed> $context view context (filter DSL, selection, locale/channel)
     */
    public function start(Tenant $tenant, Uuid $userId, AgentRunSurface $surface, string $intent, array $context = []): AgentRun
    {
        $this->guard->assertEnabled($tenant);
        $this->limits->assertWithinLimits($tenant, $userId);

        if ($this->hasActiveRun($userId)) {
            throw ActiveRunConflictException::forUser();
        }

        $run = new AgentRun($userId, $surface, $intent, $context);

        try {
            $this->entityManager->persist($run);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // uniq_agent_runs_active_per_user: a concurrent start lost the
            // race - same answer as the friendly pre-check would give.
            throw ActiveRunConflictException::forUser();
        }

        $this->messageBus->dispatch(new AgentRunMessage($run->getId(), $tenant->getId()));

        return $run;
    }

    /**
     * "Active" means GENUINELY EXECUTING — planning (the async loop is
     * calling the LLM) or committing (writing the approved batch). A run
     * parked for the human — awaiting_input (waiting for a chat reply) or
     * awaiting_approval (the autonomous stretch is done, the plan sits in
     * the inbox) — costs nothing and MUST NOT block a new conversation:
     * otherwise a parked (or stuck) run is a dead-end with no reliable
     * escape. The parked run stays in the inbox/history for its own
     * accept/reject/resume decision. Mirrors the partial unique index
     * (Version20260703130000).
     */
    private function hasActiveRun(Uuid $userId): bool
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(AgentRun::class, 'r')
            ->where('r.userId = :user')
            ->andWhere('r.status IN (:active)')
            ->setParameter('user', $userId, 'uuid')
            ->setParameter('active', [
                AgentRunStatus::Planning->value,
                AgentRunStatus::Committing->value,
            ])
            ->getQuery()
            ->getSingleScalarResult();

        return (int) (\is_scalar($count) ? $count : 0) > 0;
    }
}
