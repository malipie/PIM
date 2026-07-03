<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Application\Tool\PingTool;
use App\Agent\Application\Tool\ToolKind;
use App\Agent\Application\Tool\ToolRegistry;
use App\Identity\Contracts\Policy\AgentAutonomyResolverInterface;
use App\Identity\Contracts\Policy\PermissionCheckerInterface;
use App\Tests\Integration\Agent\AgentLoopRunnerTest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P8-05 (#1987) — the per-role autonomy gate in the registry:
 * 'off' empties the tool surface (the run cannot plan anything),
 * 'read_only' keeps grounding tools only, 'propose' is today's full
 * behaviour - and write/schema stay behind the approval gate at every
 * level by construction.
 */
final class AgentAutonomyTest extends TestCase
{
    #[Test]
    public function offEmptiesTheSurface(): void
    {
        $registry = $this->registry('off');

        self::assertSame([], $registry->availableFor(Uuid::v7()));
    }

    #[Test]
    public function readOnlyKeepsGroundingToolsOnly(): void
    {
        $registry = $this->registry('read_only');

        $names = array_map(static fn ($tool): string => $tool->name(), $registry->availableFor(Uuid::v7()));

        self::assertContains('ping', $names, 'read tools stay');
        self::assertNotContains('fake_write', $names, 'write tools disappear below propose');
    }

    #[Test]
    public function proposeKeepsTheFullSurface(): void
    {
        $registry = $this->registry('propose');

        $names = array_map(static fn ($tool): string => $tool->name(), $registry->availableFor(Uuid::v7()));

        self::assertContains('ping', $names);
        self::assertContains('fake_write', $names);
    }

    private function registry(string $level): ToolRegistry
    {
        $checker = new class implements PermissionCheckerInterface {
            public function userHasPermission(Uuid $userId, string $permissionCode): bool
            {
                return true;
            }
        };
        $resolver = new class($level) implements AgentAutonomyResolverInterface {
            public function __construct(private readonly string $level)
            {
            }

            public function autonomyForUser(Uuid $userId): string
            {
                // Narrow via a local: PHPStan does not carry in_array()
                // narrowing across repeated property fetches, so the
                // interface's literal-union return needs the copy.
                $level = $this->level;

                return \in_array($level, ['off', 'read_only', 'propose'], true) ? $level : 'off';
            }
        };

        $writeTool = new class implements \App\Agent\Application\Tool\AgentToolInterface {
            public function name(): string
            {
                return 'fake_write';
            }

            public function description(): string
            {
                return 'w';
            }

            public function parametersSchema(): array
            {
                return ['type' => 'object'];
            }

            public function requiredPermission(): string
            {
                return 'object.write';
            }

            public function kind(): ToolKind
            {
                return ToolKind::Write;
            }

            public function execute(array $arguments, \App\Agent\Application\Tool\AgentToolContext $context): array
            {
                return ['pending_change_batch_id' => AgentLoopRunnerTest::BATCH_ID, 'affected_count' => 1];
            }
        };

        return new ToolRegistry([new PingTool(), $writeTool], $checker, $resolver);
    }
}
