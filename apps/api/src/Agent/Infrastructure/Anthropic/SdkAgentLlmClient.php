<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure\Anthropic;

use Anthropic\Lib\Streaming\MessageAccumulator;
use Anthropic\Messages\Message;
use Anthropic\Messages\RawContentBlockDeltaEvent;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\TextDelta;
use Anthropic\Messages\ToolUseBlock;
use App\Agent\Application\Llm\AgentLlmClientInterface;
use App\Agent\Application\Llm\AgentLlmResponse;
use App\Agent\Application\Llm\StreamingAgentLlmClientInterface;
use App\Shared\Domain\Tenant;
use LogicException;

/**
 * AGENT-P1-03 (#1955) — the only network-facing piece of the loop:
 * builds the per-tenant SDK client (BYOK, P0-06; retry/backoff lives
 * in the SDK transport) and normalizes the response into the
 * provider-agnostic {@see AgentLlmResponse}.
 */
final readonly class SdkAgentLlmClient implements AgentLlmClientInterface, StreamingAgentLlmClientInterface
{
    private const int BACKGROUND_MAX_TOKENS = 4096;

    public function __construct(
        private AnthropicClientFactory $clientFactory,
        private int $interactiveMaxTokens,
        private int $interactiveMaxRetries,
        private float $interactiveTimeoutSeconds,
    ) {
    }

    public function create(Tenant $tenant, string $model, string $system, array $messages, array $tools, bool $promptCaching = true): AgentLlmResponse
    {
        $client = $this->clientFactory->forTenant($tenant);
        $startedAt = microtime(true);
        [$systemArg, $messageArgs] = $this->cacheArguments($system, $messages, $promptCaching);

        $message = $client->messages->create(
            model: $model,
            maxTokens: self::BACKGROUND_MAX_TOKENS,
            // @phpstan-ignore argument.type
            system: $systemArg,
            // Wire-shape arrays; the SDK normalizes them (camelCase keys).
            // @phpstan-ignore argument.type
            messages: $messageArgs,
            // @phpstan-ignore argument.type
            tools: $tools,
        );

        $durationMs = $this->elapsedMs($startedAt);

        return $this->normalize($message, $durationMs, $durationMs);
    }

    public function createStreaming(
        Tenant $tenant,
        string $model,
        string $system,
        array $messages,
        array $tools,
        bool $promptCaching,
        callable $onTextDelta,
    ): AgentLlmResponse {
        $client = $this->clientFactory->forTenant($tenant);
        [$systemArg, $messageArgs] = $this->cacheArguments($system, $messages, $promptCaching);
        $startedAt = microtime(true);
        $firstTokenMs = null;
        $accumulator = MessageAccumulator::forMessages();

        $stream = $client->messages->createStream(
            model: $model,
            maxTokens: $this->interactiveMaxTokens,
            // @phpstan-ignore argument.type
            system: $systemArg,
            // @phpstan-ignore argument.type
            messages: $messageArgs,
            // @phpstan-ignore argument.type
            tools: $tools,
            requestOptions: [
                'maxRetries' => $this->interactiveMaxRetries,
                'timeout' => $this->interactiveTimeoutSeconds,
            ],
        );

        foreach ($stream as $event) {
            $accumulator->accumulate($event);
            if (!$event instanceof RawContentBlockDeltaEvent || !$event->delta instanceof TextDelta || '' === $event->delta->text) {
                continue;
            }

            $firstTokenMs ??= $this->elapsedMs($startedAt);
            $onTextDelta($event->delta->text);
        }

        $durationMs = $this->elapsedMs($startedAt);
        $message = $accumulator->message();
        if (!$message instanceof Message) {
            throw new LogicException('Anthropic stream did not accumulate to a Messages API response.');
        }

        return $this->normalize($message, $durationMs, $firstTokenMs ?? $durationMs);
    }

    private function normalize(Message $message, int $durationMs, int $ttftMs): AgentLlmResponse
    {
        $blocks = [];
        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                $blocks[] = ['type' => 'text', 'text' => $block->text];
            } elseif ($block instanceof ToolUseBlock) {
                $blocks[] = ['type' => 'tool_use', 'id' => $block->id, 'name' => $block->name, 'input' => $block->input];
            }
        }

        return new AgentLlmResponse(
            stopReason: $message->stopReason ?? AgentLlmResponse::STOP_END_TURN,
            contentBlocks: $blocks,
            inputTokens: $message->usage->inputTokens,
            outputTokens: $message->usage->outputTokens,
            cacheReadTokens: $message->usage->cacheReadInputTokens ?? 0,
            cacheCreationTokens: $message->usage->cacheCreationInputTokens ?? 0,
            durationMs: $durationMs,
            ttftMs: $ttftMs,
        );
    }

    /**
     * Keep the system/tools prefix byte-stable across runs and retain two
     * rolling transcript breakpoints so the previous turn remains a cache
     * hit while the newest turn extends the cache.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return array{0: string|list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function cacheArguments(string $system, array $messages, bool $enabled): array
    {
        if (!$enabled) {
            return [$system, $messages];
        }

        $systemArg = [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]];
        $userIndexes = [];
        foreach ($messages as $index => &$message) {
            $content = $message['content'] ?? null;
            if (!\is_array($content)) {
                continue;
            }
            foreach ($content as &$block) {
                if (\is_array($block)) {
                    unset($block['cache_control']);
                    unset($block['cacheControl']);
                }
            }
            unset($block);
            $message['content'] = $content;
            if ('user' === ($message['role'] ?? null) && [] !== $content) {
                $userIndexes[] = $index;
            }
        }
        unset($message);

        foreach (array_slice($userIndexes, -2) as $messageIndex) {
            $content = $messages[$messageIndex]['content'];
            if (!\is_array($content) || [] === $content) {
                continue;
            }
            $lastIndex = array_key_last($content);
            if (\is_array($content[$lastIndex])) {
                $content[$lastIndex]['cacheControl'] = ['type' => 'ephemeral'];
                $messages[$messageIndex]['content'] = $content;
            }
        }

        return [$systemArg, $messages];
    }

    private function elapsedMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
