<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Application\Run\AgentSystemPromptBuilder;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * #2153 — the system prompt makes the agent act on the operator's
 * SELECTION when the list has rows checked, and ask before widening the
 * scope to the whole view.
 */
final class AgentSystemPromptBuilderTest extends TestCase
{
    #[Test]
    public function addsSelectionScopeRuleWhenTheContextHasASelection(): void
    {
        $run = new AgentRun(Uuid::v7(), AgentRunSurface::CmdK, 'raise price 20%', [
            'selected_ids' => ['id-1'],
            'total_matching' => 95,
        ]);

        $prompt = (new AgentSystemPromptBuilder())->build($run);

        self::assertStringContainsString('SELECTION SCOPE', $prompt);
        self::assertStringContainsString('1 object(s) SELECTED', $prompt);
        self::assertStringContainsString('all 95 in the active view', $prompt);
        self::assertStringContainsString('ask ONE clarifying question', $prompt);
    }

    #[Test]
    public function noSelectionRuleWhenNothingIsSelected(): void
    {
        $run = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'raise price 20%', [
            'filter_dsl' => ['field' => 'brand', 'op' => 'eq', 'value' => 'Acme'],
        ]);

        $prompt = (new AgentSystemPromptBuilder())->build($run);

        self::assertStringNotContainsString('SELECTION SCOPE', $prompt);
    }

    #[Test]
    public function anEmptySelectionAddsNoRule(): void
    {
        $run = new AgentRun(Uuid::v7(), AgentRunSurface::CmdK, 'do something', [
            'selected_ids' => [],
        ]);

        $prompt = (new AgentSystemPromptBuilder())->build($run);

        self::assertStringNotContainsString('SELECTION SCOPE', $prompt);
    }
}
