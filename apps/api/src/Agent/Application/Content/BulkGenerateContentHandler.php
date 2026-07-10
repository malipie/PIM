<?php

declare(strict_types=1);

namespace App\Agent\Application\Content;

use App\Agent\Application\AgentFeatureGuard;
use App\Agent\Application\Tool\AgentToolContext;
use App\Agent\Application\Tool\AgentToolInterface;
use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Domain\Exception\AgentUnavailableException;
use App\Shared\Application\AbstractBatchHandler;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * AICG-P6-03 (#2346, ADR-0030) — the dedicated bulk content path.
 *
 * Unlike the P5-03 agent-run loop (bounded by 10 tool-calls/run), this
 * scales to hundreds of products: it runs the SAME content write tool
 * per product (identical grounding + anti-hallucination contract +
 * pending_changes materialization), aggregating every proposal into the
 * ONE batch the run shows for approval — never a catalog write.
 *
 * Memory-bounded per §3.10: the ids are processed in batches of
 * {@see AbstractBatchHandler::$batchSize} with flush+clear, so worker
 * memory stays flat (not linear in the selection size). Usage is summed
 * locally and applied once at the end (the run detaches on every clear).
 * A single product that fails to ground or errors is SKIPPED, never
 * fatal — the rest of the batch still reaches approval.
 */
#[AsMessageHandler]
final class BulkGenerateContentHandler extends AbstractBatchHandler
{
    /** @var array<string, AgentToolInterface> */
    private array $tools;

    /**
     * @param iterable<AgentToolInterface> $tools
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        #[AutowireIterator('agent.tool')]
        iterable $tools,
        private readonly AgentFeatureGuard $guard,
        int $batchSize = 200,
    ) {
        parent::__construct($entityManager, $batchSize);
        $indexed = [];
        foreach ($tools as $tool) {
            $indexed[$tool->name()] = $tool;
        }
        $this->tools = $indexed;
    }

    public function __invoke(BulkGenerateContentMessage $message): void
    {
        $run = $this->findRun($message->runId);
        if (!$run instanceof AgentRun) {
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

        $tool = $this->tools[$message->toolName] ?? null;
        if (null === $tool) {
            $run->markError(\sprintf('Unknown content tool "%s".', $message->toolName));
            $this->entityManager->flush();

            return;
        }

        $userId = $run->getUserId();
        $context = new AgentToolContext($userId, $tenant, $run->getContext());

        $affected = 0;
        $processed = 0;
        $tokensIn = 0;
        $tokensOut = 0;
        $costUsd = 0.0;
        foreach ($message->productIds as $productId) {
            ++$processed;
            try {
                $result = $tool->execute($this->arguments($message, $productId), $context);
            } catch (Throwable) {
                // One poisoned/broken product must not sink the batch.
                $result = ['status' => 'error'];
            }

            $usage = $result['llm_usage'] ?? null;
            if (\is_array($usage)) {
                $tokensIn += \is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : 0;
                $tokensOut += \is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : 0;
                $costUsd += \is_string($usage['cost_usd'] ?? null) ? (float) $usage['cost_usd'] : 0.0;
            }
            if (($result['status'] ?? null) === 'materialized') {
                ++$affected;
            }

            if ($this->shouldFlush($processed)) {
                $this->flushAndClear();
                // The clear detached the tenant used by the tool context;
                // rebind it (same rule the loop runner follows).
                $tenant = $this->entityManager->find(Tenant::class, $message->tenantId->toRfc4122());
                if (!$tenant instanceof Tenant) {
                    return;
                }
                $context = new AgentToolContext($userId, $tenant, $context->viewContext);
            }
        }

        $run = $this->findRun($message->runId);
        if (!$run instanceof AgentRun) {
            return;
        }
        $run->addUsage($tokensIn, $tokensOut, number_format($costUsd, 6, '.', ''));
        if ($affected > 0) {
            $run->markAwaitingApproval($message->batchId, $affected);
        } else {
            // Nothing grounded well enough to propose — hand control back
            // rather than open an empty approval.
            $run->markAwaitingInput();
        }
        $this->entityManager->flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function arguments(BulkGenerateContentMessage $message, string $productId): array
    {
        $arguments = [
            'product_id' => $productId,
            'pending_change_batch_id' => $message->batchId->toRfc4122(),
        ];
        if (null !== $message->recipeId) {
            $arguments['recipe_id'] = $message->recipeId;
        }
        if (null !== $message->locale) {
            $arguments['locale'] = $message->locale;
        }
        if (null !== $message->channel) {
            $arguments['channel'] = $message->channel;
        }

        return $arguments;
    }

    private function findRun(Uuid $runId): ?AgentRun
    {
        $run = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(AgentRun::class, 'r')
            ->where('r.id = :id')
            ->setParameter('id', $runId, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $run instanceof AgentRun ? $run : null;
    }
}
