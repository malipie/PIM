<?php

declare(strict_types=1);

namespace App\Agent\Application\Llm;

/**
 * AGENT-P1-03 (#1955) — provider-agnostic view of one model turn.
 *
 * `contentBlocks` uses the Anthropic wire shape as plain arrays:
 * `{type: 'text', text}` and `{type: 'tool_use', id, name, input}` —
 * exactly what gets persisted into agent_messages and echoed back on
 * the next request.
 *
 * @phpstan-type ContentBlock array<string, mixed>
 */
final readonly class AgentLlmResponse
{
    public const string STOP_TOOL_USE = 'tool_use';
    public const string STOP_END_TURN = 'end_turn';

    /**
     * @param list<array<string, mixed>> $contentBlocks
     * @param int                        $cacheReadTokens     tokens served from the prompt cache (~0.1x price)
     * @param int                        $cacheCreationTokens tokens written to the prompt cache (~1.25x price, 5-min TTL)
     */
    public function __construct(
        public string $stopReason,
        public array $contentBlocks,
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheReadTokens = 0,
        public int $cacheCreationTokens = 0,
        public int $durationMs = 0,
        public int $ttftMs = 0,
    ) {
    }

    /**
     * @return list<array{id: string, name: string, input: array<string, mixed>}>
     */
    public function toolUses(): array
    {
        $uses = [];
        foreach ($this->contentBlocks as $block) {
            if (($block['type'] ?? null) !== 'tool_use') {
                continue;
            }
            $id = $block['id'] ?? null;
            $name = $block['name'] ?? null;
            if (!\is_string($id) || !\is_string($name)) {
                continue;
            }
            /** @var array<string, mixed> $input */
            $input = \is_array($block['input'] ?? null) ? $block['input'] : [];
            $uses[] = ['id' => $id, 'name' => $name, 'input' => $input];
        }

        return $uses;
    }
}
