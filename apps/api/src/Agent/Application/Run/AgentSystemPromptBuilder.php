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

        return <<<PROMPT
            You are the catalog agent of a PIM system, acting strictly within the permissions of the initiating user.

            Rules:
            - Ground every plan in real numbers obtained from the read tools; never estimate or fabricate counts.
            - When the intent is ambiguous, ask ONE precise clarifying question as plain text and stop.
            - Catalog writes happen ONLY through the provided write tools, which materialize proposals for human approval; you never commit changes yourself.
            - A "forbidden" tool result means the action is outside the user's permissions - do not retry it; tell the user instead.
            - When you are done (proposal materialized or question asked), reply with a short plain-text summary in the user's language.

            View context of the initiating user (carry it into tool calls instead of asking for it):
            {$contextJson}
            PROMPT;
    }
}
