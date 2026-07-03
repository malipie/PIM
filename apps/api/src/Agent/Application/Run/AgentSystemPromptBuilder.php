<?php

declare(strict_types=1);

namespace App\Agent\Application\Run;

use App\Agent\Domain\Entity\AgentRun;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * AGENT-P1-03 (#1955) — deterministic system prompt of the loop
 * (PRD 5.2): ground plans in read tools, ask when ambiguous, write
 * only through approval-gated tools, never fabricate numbers.
 */
final readonly class AgentSystemPromptBuilder
{
    public function build(AgentRun $run): string
    {
        $context = $run->getContext();
        $contextJson = [] === $context
            ? 'none'
            : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $scopeRule = $this->scopeRule($context);

        return <<<PROMPT
            You are the catalog agent of a PIM system, acting strictly within the permissions of the initiating user.

            Rules:
            - Ground every plan in real numbers obtained from the read tools; never estimate or fabricate counts.
            - When the intent is ambiguous, ask ONE precise clarifying question as plain text and stop.
            - Catalog writes happen ONLY through the provided write tools, which materialize proposals for human approval; you never commit changes yourself.
            - A "forbidden" tool result means the action is outside the user's permissions - do not retry it; tell the user instead.
            - HIGH-LEVEL intents (e.g. "prepare the DE launch for category X") are multi-step plans: break them into a sequence of tool calls in ONE run - ground first, then materialize each step. Later write-tool calls automatically append to the same proposal batch, so the operator approves ONE collective diff; keep the steps within the run's tool-call budget.
            - When you are done (proposal materialized or question asked), reply with a short plain-text summary in the user's language: for multi-step plans list each step with its numbers.{$scopeRule}

            View context of the initiating user (carry it into tool calls instead of asking for it):
            {$contextJson}
            PROMPT;
    }

    /**
     * #2153 — when the operator has rows SELECTED in the list, the agent
     * must act on that selection, not the whole view, and must ask before
     * widening the scope. Returns an extra rule line (leading newline +
     * indent to sit in the bullet list) or '' when there is no selection.
     *
     * @param array<string, mixed> $context
     */
    private function scopeRule(array $context): string
    {
        $selected = $context['selected_ids'] ?? null;
        if (!\is_array($selected)) {
            return '';
        }
        $count = \count(array_filter($selected, static fn (mixed $id): bool => \is_string($id) && '' !== $id));
        if (0 === $count) {
            return '';
        }

        $total = $context['total_matching'] ?? null;
        $totalPhrase = \is_int($total) && $total > $count
            ? \sprintf('all %d in the active view', $total)
            : 'the whole list';

        return "\n            - SELECTION SCOPE: the operator has {$count} object(s) SELECTED in the list. The write tools default to this selection when you omit object_ids and filter_dsl - use that default. Only target {$totalPhrase} if the intent CLEARLY says so (e.g. \"all\", \"every\", \"the whole list\"). If it is not clear whether they mean the {$count} selected or {$totalPhrase}, ask ONE clarifying question first and stop.";
    }
}
