<?php

declare(strict_types=1);

namespace App\Agent\Application\Run;

use App\Agent\Application\AgentFeatureGuard;
use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Domain\Exception\AgentUnavailableException;
use App\Shared\Application\AbstractBatchHandler;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * AGENT-P1-03 (#1955) — worker entry of the loop. Tenant context + RLS
 * GUC are rebound by the shared middlewares (TenantAwareMessage); the
 * feature guard re-checks availability IN the worker (the key may have
 * been disabled between dispatch and pickup). Extends
 * AbstractBatchHandler for the final flush+clear (worker memory rule).
 */
#[AsMessageHandler]
final class AgentRunHandler extends AbstractBatchHandler
{
    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly AgentLoopRunner $loop,
        private readonly AgentFeatureGuard $guard,
    ) {
        parent::__construct($entityManager);
    }

    public function __invoke(AgentRunMessage $message): void
    {
        try {
            $run = $this->entityManager->createQueryBuilder()
                ->select('r')
                ->from(AgentRun::class, 'r')
                ->where('r.id = :id')
                ->setParameter('id', $message->runId, 'uuid')
                ->getQuery()
                ->getOneOrNullResult();

            if (!$run instanceof AgentRun) {
                // Deleted / foreign-tenant run: nothing to do.
                return;
            }

            $tenant = $run->getTenant();
            if (!$tenant instanceof Tenant) {
                return;
            }

            try {
                $this->guard->assertEnabled($tenant);
            } catch (AgentUnavailableException $unavailable) {
                $run->markError($unavailable->getMessage());
                $this->entityManager->flush();

                return;
            }

            $this->loop->run($run, $tenant);
        } finally {
            $this->flushAndClear();
        }
    }
}
