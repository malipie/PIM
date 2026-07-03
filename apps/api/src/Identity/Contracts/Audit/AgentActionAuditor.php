<?php

declare(strict_types=1);

namespace App\Identity\Contracts\Audit;

/**
 * AGENT-P3-07 (#1967) — cross-context contract for first-class agent
 * audit events (PRD §10.5: "who approved, what did the agent do, when,
 * with which model, at what cost").
 *
 * The Agent BC (Deptrac: Agent_Internals may only reach `*_Contracts` +
 * Shared) writes accountability entries into the SAME append-only DH
 * Auditor as the rest of the platform — never a separate log, so the
 * question "kto zawinił?" has one answer surface. `agent_tool_calls`
 * stays the step-by-step technical detail; audit_logs is the
 * accountability layer.
 *
 * The ACTOR (the user in whose permissions the agent acted — the run
 * owner) is explicit because agent transitions can happen off-request
 * (Messenger worker, no security token). The approver / decision-maker
 * travels in $details.
 */
interface AgentActionAuditor
{
    /**
     * @param string               $action  audit action code, e.g. agent_run_started,
     *                                      agent_batch_committed, agent_run_rejected,
     *                                      agent_run_cancelled, agent_batch_rolled_back
     * @param string               $runId   agent run id (RFC 4122) — the audit resource_id
     * @param string|null          $actorId run owner user id (RFC 4122)
     * @param array<string, mixed> $details model / tokens / cost / approver / batch info → audit new_value
     */
    public function recordAgentAction(string $action, string $runId, ?string $actorId, array $details): void;
}
