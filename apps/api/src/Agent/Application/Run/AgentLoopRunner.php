<?php

declare(strict_types=1);

namespace App\Agent\Application\Run;

use App\Agent\Application\Limits\AgentLimitGuard;
use App\Agent\Application\Llm\AgentLlmClientInterface;
use App\Agent\Application\Llm\AgentLlmResponse;
use App\Agent\Application\Tool\AgentToolContext;
use App\Agent\Application\Tool\GuardedToolExecutor;
use App\Agent\Application\Tool\ToolKind;
use App\Agent\Application\Tool\ToolRegistry;
use App\Agent\Domain\Entity\AgentMessage;
use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Infrastructure\Anthropic\AgentModelSelector;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * AGENT-P1-03 (#1955) — the agent loop (PRD 5.2):
 * plan -> (read tools) -> clarifying question | materialized proposal.
 *
 * Autonomy ends BEFORE approval (ADR-0024 c): the loop stops at
 * `awaiting_approval` the moment a write tool materializes a
 * pending_changes batch (its result carries `pending_change_batch_id`);
 * the commit happens in P3-02 after a human accepts. A turn with no
 * tool calls is a clarifying question / final answer -> `awaiting_input`.
 *
 * In-run limits (PRD 10.4, hard 8.5 subset): tool calls per run and
 * tokens per run are enforced INSIDE the loop; hourly/daily/monthly
 * budgets + kill-switch layer on top in P1-04. Any exhaustion or LLM
 * failure -> run `error`, zero partial commit (there is nothing to
 * commit before approval by construction).
 */
final readonly class AgentLoopRunner
{
    public function __construct(
        private AgentLlmClientInterface $llm,
        private AgentLimitGuard $limits,
        private ToolRegistry $registry,
        private GuardedToolExecutor $executor,
        private AgentModelSelector $models,
        private AgentSystemPromptBuilder $prompts,
        private UsageCostCalculator $costs,
        private EntityManagerInterface $entityManager,
        private \Symfony\Component\Messenger\MessageBusInterface $eventBus,
        private int $maxToolCallsPerRun,
        private int $maxTokensPerRun,
    ) {
    }

    public function run(AgentRun $run, Tenant $tenant): void
    {
        $userId = $run->getUserId();
        $context = new AgentToolContext($userId, $tenant, $run->getContext());

        $tools = $this->registry->toAnthropicToolDefs($userId);
        $model = $this->registry->hasKind($userId, ToolKind::Schema)
            ? $this->models->schemaModel()
            : $this->models->defaultModel();
        $run->setModel($model);

        $system = $this->prompts->build($run);
        // AGENT-P4-01 (#1968) — a re-dispatched run (next user turn after
        // awaiting_input) resumes from the persisted transcript instead of
        // restarting at the intent.
        $messages = $this->loadTranscript($run);
        if ([] === $messages) {
            $userTurn = [['type' => 'text', 'text' => $run->getIntent()]];
            $messages = [['role' => 'user', 'content' => $userTurn]];
            $this->persistMessage($run, AgentMessage::ROLE_USER, $userTurn);
        }

        $toolCallCount = 0;
        // AGENT-P8-03 (#1985) — the collective plan of a multi-step intent.
        $planBatchId = null;
        $planAffected = 0;

        try {
            while (true) {
                try {
                    // 8.5 budgets (hour/day/month) re-checked before every
                    // model call - a run cannot spend past a cap mid-flight.
                    $this->limits->assertWithinLimits($tenant, $userId);
                } catch (\App\Agent\Domain\Exception\AgentLimitExceededException $limit) {
                    $run->markError($limit->getMessage());

                    return;
                }

                $response = $this->llm->create($tenant, $model, $system, $messages, $tools);
                $run->addUsage(
                    $response->inputTokens,
                    $response->outputTokens,
                    $this->costs->costUsd($model, $response->inputTokens, $response->outputTokens),
                );
                $this->persistMessage($run, AgentMessage::ROLE_ASSISTANT, $response->contentBlocks);
                $messages[] = ['role' => 'assistant', 'content' => $response->contentBlocks];

                if ($run->getTokensInput() + $run->getTokensOutput() > $this->maxTokensPerRun) {
                    $run->markError(\sprintf('Token limit per run exceeded (%d).', $this->maxTokensPerRun));

                    return;
                }

                $toolUses = $response->toolUses();
                if ([] === $toolUses || AgentLlmResponse::STOP_TOOL_USE !== $response->stopReason) {
                    // AGENT-P8-03 (#1985) — end of the model's plan: with an
                    // accumulated batch this is the COLLECTIVE diff going to
                    // approval; without one it is a clarifying question /
                    // summary handing control back to the user.
                    if (null !== $planBatchId) {
                        $run->markAwaitingApproval(
                            \Symfony\Component\Uid\Uuid::fromString($planBatchId),
                            $planAffected,
                        );
                        $this->announcePlanReady($run, $planAffected);

                        return;
                    }
                    $run->markAwaitingInput();

                    return;
                }

                if ($toolCallCount + \count($toolUses) > $this->maxToolCallsPerRun) {
                    $run->markError(\sprintf('Tool-call limit per run exceeded (%d).', $this->maxToolCallsPerRun));

                    return;
                }

                $toolResults = [];
                foreach ($toolUses as $toolUse) {
                    // AGENT-P8-03 (#1985) — multi-step plans accumulate into
                    // ONE batch: once a write tool materialized, every later
                    // write-tool call inherits the batch id (unless the model
                    // explicitly passes one).
                    $input = $toolUse['input'];
                    if (null !== $planBatchId && !\array_key_exists('pending_change_batch_id', $input)) {
                        $input['pending_change_batch_id'] = $planBatchId;
                    }

                    $outcome = $this->executor->execute($run, $toolUse['name'], $input, $context);
                    ++$toolCallCount;

                    $toolResults[] = [
                        'type' => 'tool_result',
                        'toolUseID' => $toolUse['id'],
                        'content' => $this->encodeToolResult($outcome['content']),
                        'isError' => $outcome['is_error'],
                    ];

                    $batchId = $outcome['content']['pending_change_batch_id'] ?? null;
                    if (!$outcome['is_error'] && \is_string($batchId)) {
                        $affected = $outcome['content']['affected_count'] ?? 0;
                        $planBatchId ??= $batchId;
                        if ($batchId === $planBatchId) {
                            $planAffected += \is_int($affected) ? $affected : 0;
                        }
                    }
                }

                // A write tool (bulk edit / schema import) clears the EM
                // mid-loop, detaching the run and the context tenant.
                // Re-attach both before persisting the tool-result message
                // and any status transition below (AGENT-P9-01 red-team).
                if (!$this->entityManager->contains($run)) {
                    $reloaded = $this->entityManager->find(AgentRun::class, $run->getId());
                    if ($reloaded instanceof AgentRun) {
                        $run = $reloaded;
                    }
                    $managedTenant = $this->entityManager->find(Tenant::class, $tenant->getId()->toRfc4122());
                    if ($managedTenant instanceof Tenant) {
                        $context = new AgentToolContext($run->getUserId(), $managedTenant, $run->getContext());
                    }
                }

                $this->persistMessage($run, AgentMessage::ROLE_TOOL, $toolResults);
                $messages[] = ['role' => 'user', 'content' => $toolResults];
            }
        } catch (Throwable $failure) {
            // Backoff exhaustion (SDK), missing key, or an unexpected bug:
            // the run ends in `error`; nothing was committed (commits only
            // happen post-approval in P3-02).
            $run->markError($failure::class.': '.$failure->getMessage());
        } finally {
            $this->entityManager->flush();
        }
    }

    /**
     * @param array<string, mixed> $content
     */
    private function encodeToolResult(array $content): string
    {
        $encoded = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return false === $encoded ? '{}' : $encoded;
    }

    /**
     * AGENT-P8-03/P8-04 — "the collective plan is ready" webhook,
     * best-effort.
     */
    private function announcePlanReady(AgentRun $run, int $affected): void
    {
        try {
            $this->eventBus->dispatch(new \App\Shared\Contracts\Event\AgentRunAwaitingApproval(
                $run->getId(),
                $run->getIntent(),
                $affected,
            ));
        } catch (Throwable) {
            // Never let a webhook failure kill the run.
        }
    }

    /**
     * Rebuild the Anthropic-shaped transcript from agent_messages: user
     * and assistant turns map 1:1, tool-result turns are user turns (the
     * Messages API shape for tool results).
     *
     * @return list<array{role: string, content: list<array<string, mixed>>}>
     */
    private function loadTranscript(AgentRun $run): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(AgentMessage::class, 'm')
            ->where('m.run = :run')
            ->setParameter('run', $run)
            ->orderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();

        $messages = [];
        /** @var list<AgentMessage> $rows */
        foreach ($rows as $row) {
            /** @var list<array<string, mixed>> $content */
            $content = array_values($row->getContent());
            $messages[] = [
                'role' => AgentMessage::ROLE_ASSISTANT === $row->getRole() ? 'assistant' : 'user',
                'content' => $content,
            ];
        }

        return $messages;
    }

    /**
     * @param list<array<string, mixed>> $content
     */
    private function persistMessage(AgentRun $run, string $role, array $content): void
    {
        $message = new AgentMessage($run, $role, $content);
        // Assign the run's managed tenant directly: a clearing write tool
        // detaches the TenantContext tenant, so the TenantAssignmentListener
        // cannot stamp this message here (AGENT-P9-01).
        $runTenant = $run->getTenant();
        if ($runTenant instanceof Tenant) {
            $message->assignTenant($runTenant);
        }
        $this->entityManager->persist($message);
        $this->entityManager->flush();
    }
}
