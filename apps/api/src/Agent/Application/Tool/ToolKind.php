<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

/**
 * AGENT-P0-07 (#1950) — coarse tool classification (ADR-0024 b).
 *
 * Drives (a) the model choice per run (`schema` -> Opus-tier, the rest
 * -> Sonnet-tier default; AgentModelSelector) and (b) the write/approval
 * semantics: `write`/`schema` tools materialize into pending_changes
 * and commit only after human approval; `read` tools ground the plan;
 * `action` tools trigger engines (e.g. export) without a catalog write.
 */
enum ToolKind: string
{
    case Read = 'read';
    case Write = 'write';
    case Schema = 'schema';
    case Action = 'action';
}
