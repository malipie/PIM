<?php

declare(strict_types=1);

namespace App\Agent\Domain\Exception;

use RuntimeException;

/**
 * AGENT-P0-07 (#1950) — a tool call outside the user's RBAC scope.
 *
 * Raised by the registry on execute() when the user lacks the tool's
 * required permission — the hard boundary that makes prompt-injection
 * harmless beyond the user's own scope (ADR-0024 b). The loop (P1-03)
 * converts it into a "forbidden" tool_result so the model learns it
 * cannot do that, and the attempt is audited (P1-02/P3-07).
 */
final class ToolAccessDeniedException extends RuntimeException
{
    public static function forTool(string $toolName): self
    {
        return new self(\sprintf('Tool "%s" is not available: missing required permission.', $toolName));
    }

    public static function unknownTool(string $toolName): self
    {
        // Same message shape as the permission denial on purpose: do not
        // let a prompt enumerate which tools exist beyond the user scope.
        return new self(\sprintf('Tool "%s" is not available: missing required permission.', $toolName));
    }
}
