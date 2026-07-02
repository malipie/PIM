<?php

declare(strict_types=1);

namespace App\Agent\Application\Run;

use App\Agent\Domain\Entity\AgentRun;
use App\Shared\Infrastructure\Mercure\MercureSubscribeTopics;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Throwable;

use const JSON_THROW_ON_ERROR;

/**
 * AGENT-P1-05 (#1957) — Mercure SSE publisher for agent runs, mirroring
 * ExportProgressPublisher: two tenant-scoped PRIVATE topics
 * (`tenant/{tid}/agent-runs/{run_id}` for the open panel,
 * `tenant/{tid}/agent-runs/user/{user_id}` for the history/inbox list).
 * Hub failures are logged, never abort the run — Mercure is a
 * notification channel; agent_runs.status is the source of truth
 * (FE falls back to polling, P6-07).
 */
final class AgentProgressPublisher
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HubInterface $hub,
        private readonly string $topicBase,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Fine-grained phase tick while the run works (planning, a named
     * tool call, materializing) — more granular than the status enum.
     */
    public function progress(AgentRun $run, string $phase): void
    {
        $this->publish($run, 'progress', ['phase' => $phase]);
    }

    /**
     * Status transition of the run lifecycle (awaiting_input /
     * awaiting_approval / committing / done / error / rolled_back).
     */
    public function status(AgentRun $run): void
    {
        $this->publish($run, 'status', [
            'status' => $run->getStatus()->value,
            'affected_count' => $run->getAffectedCount(),
            'error_message' => $run->getErrorMessage(),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function publish(AgentRun $run, string $eventType, array $payload): void
    {
        $tenant = $run->getTenant();
        if (null === $tenant) {
            $this->logger->warning('Agent progress publish skipped: run has no tenant', [
                'run_id' => $run->getId()->toRfc4122(),
                'event' => $eventType,
            ]);

            return;
        }

        $encoded = json_encode([
            'event' => $eventType,
            'run_id' => $run->getId()->toRfc4122(),
            ...$payload,
        ], JSON_THROW_ON_ERROR);

        $tenantId = $tenant->getId();
        $runTopic = MercureSubscribeTopics::agentRun($tenantId, $this->topicBase, $run->getId()->toRfc4122());
        $userTopic = MercureSubscribeTopics::agentUser($tenantId, $this->topicBase, $run->getUserId()->toRfc4122());

        try {
            $this->hub->publish(new Update($runTopic, $encoded, private: true));
            $this->hub->publish(new Update($userTopic, $encoded, private: true));
        } catch (Throwable $error) {
            $this->logger->warning('Agent progress publish failed', [
                'run_id' => $run->getId()->toRfc4122(),
                'event' => $eventType,
                'error' => $error->getMessage(),
            ]);
        }
    }
}
