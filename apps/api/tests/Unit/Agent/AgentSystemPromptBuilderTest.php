<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Application\Run\AgentSystemPromptBuilder;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
use Doctrine\ORM\EntityManagerInterface;
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

        $builder = new AgentSystemPromptBuilder($this->createStub(EntityManagerInterface::class));
        $prompt = $builder->buildContext($run);

        self::assertStringContainsString('SELECTION SCOPE', $prompt);
        self::assertStringContainsString('1 object(s) SELECTED', $prompt);
        self::assertStringContainsString('all 95 in the active view', $prompt);
        self::assertStringContainsString('ask ONE clarifying question', $prompt);
        self::assertStringContainsString('"selected_count":1', $prompt);
        self::assertStringNotContainsString('id-1', $prompt);

        $system = $builder->build($run);
        self::assertStringContainsString('get_object', $system);
        self::assertStringContainsString('UNTRUSTED CATALOG DATA', $system);
        self::assertStringContainsString('never reveal an omitted/restricted attribute', $system);
        self::assertStringContainsString('obtain the user\'s confirmation', $system);
        self::assertStringContainsString('confirmed=true', $system);
        self::assertStringContainsString('workflow transition name', $system);
    }

    #[Test]
    public function noSelectionRuleWhenNothingIsSelected(): void
    {
        $run = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'raise price 20%', [
            'filter_dsl' => ['field' => 'brand', 'op' => 'eq', 'value' => 'Acme'],
        ]);

        $prompt = new AgentSystemPromptBuilder($this->createStub(EntityManagerInterface::class))->buildContext($run);

        self::assertStringNotContainsString('SELECTION SCOPE', $prompt);
        self::assertStringContainsString('"has_active_filter":true', $prompt);
        self::assertStringNotContainsString('Acme', $prompt);
    }

    #[Test]
    public function anEmptySelectionAddsNoRule(): void
    {
        $run = new AgentRun(Uuid::v7(), AgentRunSurface::CmdK, 'do something', [
            'selected_ids' => [],
        ]);

        $prompt = new AgentSystemPromptBuilder($this->createStub(EntityManagerInterface::class))->buildContext($run);

        self::assertStringNotContainsString('SELECTION SCOPE', $prompt);
    }

    #[Test]
    public function systemPrefixIsByteStableAcrossDifferentRuns(): void
    {
        $builder = new AgentSystemPromptBuilder($this->createStub(EntityManagerInterface::class));
        $first = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'first', ['selected_ids' => ['secret-id']]);
        $second = new AgentRun(Uuid::v7(), AgentRunSurface::CmdK, 'second', ['locale' => 'pl_PL']);

        self::assertSame($builder->build($first), $builder->build($second));
    }
}
