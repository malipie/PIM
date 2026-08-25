<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use Anthropic\Messages\Message;
use Anthropic\Messages\ToolUseBlock;
use Anthropic\Messages\Usage;
use App\Agent\Application\Llm\AgentLlmResponse;
use App\Agent\Infrastructure\Anthropic\SdkAgentLlmClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class SdkAgentLlmClientTest extends TestCase
{
    #[Test]
    public function normalizesRawToolInputFromAStreamedSdkBlock(): void
    {
        $toolUse = ToolUseBlock::with('tool-1', [], 'search_catalog');
        $toolUse['input'] = (object) [
            'query' => 'buty',
            'filter_dsl' => (object) ['operator' => 'AND', 'conditions' => []],
        ];
        $message = Message::with(
            id: 'message-1',
            container: null,
            content: [$toolUse],
            model: 'claude-test',
            stopDetails: null,
            stopReason: AgentLlmResponse::STOP_TOOL_USE,
            stopSequence: null,
            usage: Usage::with(null, 0, 0, null, 10, 5, null, null, null),
        );

        $client = new ReflectionClass(SdkAgentLlmClient::class)->newInstanceWithoutConstructor();
        $normalize = new ReflectionMethod(SdkAgentLlmClient::class, 'normalize');
        $response = $normalize->invoke($client, $message, 12, 4);

        self::assertInstanceOf(AgentLlmResponse::class, $response);
        self::assertSame([[
            'type' => 'tool_use',
            'id' => 'tool-1',
            'name' => 'search_catalog',
            'input' => [
                'query' => 'buty',
                'filter_dsl' => ['operator' => 'AND', 'conditions' => []],
            ],
        ]], $response->contentBlocks);
    }
}
