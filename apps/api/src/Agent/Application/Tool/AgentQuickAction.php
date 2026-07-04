<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

/**
 * #2246 — a quick-action chip surfaced on the dashboard hero and in the
 * global Cmd+K palette. Declared BY the tool itself (via
 * {@see ProvidesQuickActionInterface}), so a new tool that ships a chip
 * needs zero wiring anywhere else — the capabilities endpoint collects
 * these from the RBAC-filtered registry.
 */
final readonly class AgentQuickAction
{
    /**
     * @param array{pl: string, en: string} $label  chip caption
     * @param array{pl: string, en: string} $prompt prefill for the agent prompt input
     */
    public function __construct(
        public string $id,
        public array $label,
        public array $prompt,
        public int $priority = 100,
    ) {
    }

    /**
     * @return array{id: string, label: array{pl: string, en: string}, prompt: array{pl: string, en: string}}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'prompt' => $this->prompt,
        ];
    }
}
