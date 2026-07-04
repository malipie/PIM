<?php

declare(strict_types=1);

namespace App\Agent\Domain;

/**
 * AGENT-P1-01 (#1953) — where the run was initiated (PRD 5.1): the
 * docked chat panel or the Cmd+K palette. Both are equal-rights entry
 * points into the same loop/approval flow.
 */
enum AgentRunSurface: string
{
    case Chat = 'chat';
    case CmdK = 'cmdk';
    // AGENT-P8-01 (#1983) — runs the proactive data-steward scan opens
    // without a human prompt.
    case Proactive = 'proactive';
    // #2246 — the dashboard command hero, third human entry point with
    // the same loop/approval flow.
    case Dashboard = 'dashboard';
}
