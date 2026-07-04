<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

/**
 * #2246 — OPTIONAL companion to {@see AgentToolInterface}: a tool that
 * implements it advertises a one-click chip (dashboard hero + Cmd+K).
 * Tools without it stay reachable through free-text prompts — the chip
 * is a discoverability affordance, not a capability gate. RBAC/autonomy
 * filtering rides on ToolRegistry::availableFor(), same as the tool
 * surface handed to the model.
 */
interface ProvidesQuickActionInterface
{
    public function quickAction(): AgentQuickAction;
}
