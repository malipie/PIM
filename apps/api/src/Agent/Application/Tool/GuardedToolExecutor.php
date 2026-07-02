<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Domain\Entity\AgentToolCall;
use App\Agent\Domain\Exception\ToolAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * AGENT-P1-02 (#1954) — runtime enforcement wrapper around the tool
 * registry: EVERY tool call the loop executes goes through here.
 *
 * - The registry re-checks the coarse permission on execute (P0-07);
 *   fine-grained per-attribute/locale/channel checks run INSIDE the
 *   tools via {@see \App\Identity\Contracts\Policy\UserScopedPermissionCheckerInterface}
 *   (the loop has no security token — checks go by the initiating
 *   user's id).
 * - A denial is NOT an exception to the loop: it becomes a "forbidden"
 *   tool_result the model can read (it learns it cannot do that), and
 *   the attempt is recorded.
 * - Every call — allowed, forbidden or crashed — persists an
 *   AgentToolCall row (rbac_checked, duration, compact summary) so the
 *   run's technical trace is complete (accountability layer on top is
 *   DH Auditor, P3-07).
 */
final readonly class GuardedToolExecutor
{
    public function __construct(
        private ToolRegistry $registry,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{is_error: bool, content: array<string, mixed>}
     */
    public function execute(AgentRun $run, string $toolName, array $arguments, AgentToolContext $context): array
    {
        $tool = $this->registry->find($toolName);
        $kind = null !== $tool ? $tool->kind()->value : 'unknown';

        $toolCall = new AgentToolCall($run, $toolName, $kind, $arguments);
        $startedAt = microtime(true);

        try {
            $result = $this->registry->execute($toolName, $arguments, $context);
            $toolCall->complete(
                $this->summarize($result),
                rbacChecked: true,
                durationMs: $this->elapsedMs($startedAt),
            );

            return ['is_error' => false, 'content' => $result];
        } catch (ToolAccessDeniedException $denied) {
            $toolCall->complete(
                ['forbidden' => true],
                rbacChecked: true,
                durationMs: $this->elapsedMs($startedAt),
            );

            return [
                'is_error' => true,
                'content' => ['error' => 'forbidden', 'message' => $denied->getMessage()],
            ];
        } catch (Throwable $failure) {
            $toolCall->complete(
                ['failed' => true],
                rbacChecked: true,
                durationMs: $this->elapsedMs($startedAt),
            );

            // The message may reference internals; the model gets a
            // generic failure, the trace keeps the exception class only.
            return [
                'is_error' => true,
                'content' => ['error' => 'tool_failed', 'exception' => $failure::class],
            ];
        } finally {
            $this->entityManager->persist($toolCall);
            $this->entityManager->flush();
        }
    }

    /**
     * Compact, secret-free summary for the technical trace: top-level
     * scalar fields + counts for arrays. Full payloads stay out of the
     * DB (a search result can be megabytes).
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function summarize(array $result): array
    {
        $summary = [];
        foreach ($result as $key => $value) {
            if (\is_scalar($value) || null === $value) {
                $summary[$key] = $value;
            } elseif (\is_array($value)) {
                $summary[$key] = \sprintf('array(%d)', \count($value));
            } else {
                $summary[$key] = \get_debug_type($value);
            }
        }

        return $summary;
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
