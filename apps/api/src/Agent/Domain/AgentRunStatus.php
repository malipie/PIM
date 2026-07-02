<?php

declare(strict_types=1);

namespace App\Agent\Domain;

/**
 * AGENT-P1-01 (#1953) — lifecycle of an agent run (PRD 5.8, ADR-0024 c).
 *
 * Autonomy ends BEFORE approval: planning -> (awaiting_input) ->
 * awaiting_approval is the autonomous stretch; committing/done happen
 * only after a human accepts the pending_changes batch (P3-02).
 */
enum AgentRunStatus: string
{
    case Planning = 'planning';
    case AwaitingInput = 'awaiting_input';
    case AwaitingApproval = 'awaiting_approval';
    case Committing = 'committing';
    case Done = 'done';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Error = 'error';
    case RolledBack = 'rolled_back';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Done, self::Rejected, self::Cancelled, self::Error, self::RolledBack => true,
            default => false,
        };
    }
}
