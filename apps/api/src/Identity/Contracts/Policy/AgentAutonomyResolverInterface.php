<?php

declare(strict_types=1);

namespace App\Identity\Contracts\Policy;

use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P8-05 (#1987) — the trust level the user's ROLES grant the
 * agent: 'off' (no agent), 'read_only' (grounding tools only) or
 * 'propose' (full tool surface; write/schema still ALWAYS go through
 * the approval gate - autonomy never unlocks unattended commits).
 * Roles aggregate additively like permissions: the MOST permissive
 * level among the user's roles wins.
 */
interface AgentAutonomyResolverInterface
{
    /**
     * @return 'off'|'read_only'|'propose'
     */
    public function autonomyForUser(Uuid $userId): string;
}
