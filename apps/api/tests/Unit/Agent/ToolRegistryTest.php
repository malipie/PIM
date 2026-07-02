<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Application\Tool\AgentToolContext;
use App\Agent\Application\Tool\AgentToolInterface;
use App\Agent\Application\Tool\PingTool;
use App\Agent\Application\Tool\ToolKind;
use App\Agent\Application\Tool\ToolRegistry;
use App\Agent\Domain\Exception\ToolAccessDeniedException;
use App\Identity\Contracts\Policy\PermissionCheckerInterface;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P0-07 (#1950, SEC failing-test-first) — the registry is a hard
 * RBAC boundary: a tool whose permission the user lacks is (a) absent
 * from the model-facing defs and (b) refused even on a direct execute()
 * call. Prompt-injection cannot reach a tool outside the user's scope.
 */
final class ToolRegistryTest extends TestCase
{
    #[Test]
    public function toolWithoutPermissionIsAbsentFromDefsAndBlockedOnDirectExecute(): void
    {
        $userId = Uuid::v7();
        $registry = new ToolRegistry(
            [new PingTool(), $this->schemaTool()],
            // User may read the catalog but has NO modeling permission.
            $this->checker(['object.read']),
        );

        $defs = $registry->toAnthropicToolDefs($userId);
        self::assertSame(['ping'], array_column($defs, 'name'), 'schema tool must not be exposed to the model');

        // Direct execute of the hidden tool (forged tool_use) is refused.
        $this->expectException(ToolAccessDeniedException::class);
        $registry->execute('create_attribute_stub', [], $this->context($userId));
    }

    #[Test]
    public function unknownToolIsIndistinguishableFromForbiddenTool(): void
    {
        $userId = Uuid::v7();
        $registry = new ToolRegistry([new PingTool()], $this->checker(['object.read']));

        $forbidden = null;
        $unknown = null;
        try {
            new ToolRegistry([new PingTool()], $this->checker([]))->execute('ping', [], $this->context($userId));
        } catch (ToolAccessDeniedException $e) {
            $forbidden = $e->getMessage();
        }
        try {
            $registry->execute('no_such_tool', [], $this->context($userId));
        } catch (ToolAccessDeniedException $e) {
            $unknown = $e->getMessage();
        }

        self::assertNotNull($forbidden);
        self::assertNotNull($unknown);
        self::assertSame(
            str_replace('ping', 'X', $forbidden),
            str_replace('no_such_tool', 'X', $unknown),
            'unknown vs forbidden must not be distinguishable (no tool enumeration)',
        );
    }

    #[Test]
    public function allowedToolIsListedAndExecutes(): void
    {
        $userId = Uuid::v7();
        $registry = new ToolRegistry([new PingTool()], $this->checker(['object.read']));

        $tools = $registry->availableFor($userId);
        self::assertCount(1, $tools);
        self::assertSame('ping', $tools[0]->name());
        self::assertSame(ToolKind::Read, $tools[0]->kind());

        $defs = $registry->toAnthropicToolDefs($userId);
        self::assertSame('ping', $defs[0]['name']);
        self::assertNotSame('', $defs[0]['description']);
        self::assertSame('object', $defs[0]['inputSchema']['type']);

        $result = $registry->execute('ping', ['echo' => 'hello'], $this->context($userId));
        self::assertTrue($result['pong']);
        self::assertSame('alpha', $result['tenant']);
        self::assertSame('hello', $result['echo']);
    }

    #[Test]
    public function kindLookupDrivesModelSelection(): void
    {
        $userId = Uuid::v7();

        $withSchema = new ToolRegistry(
            [new PingTool(), $this->schemaTool()],
            $this->checker(['object.read', 'attribute.write']),
        );
        self::assertTrue($withSchema->hasKind($userId, ToolKind::Schema));

        $schemaHidden = new ToolRegistry(
            [new PingTool(), $this->schemaTool()],
            $this->checker(['object.read']),
        );
        self::assertFalse($schemaHidden->hasKind($userId, ToolKind::Schema), 'RBAC-hidden schema tool must not flip the run onto the Opus tier');
    }

    /**
     * @param list<string> $granted
     */
    private function checker(array $granted): PermissionCheckerInterface
    {
        return new class($granted) implements PermissionCheckerInterface {
            /** @param list<string> $granted */
            public function __construct(private readonly array $granted)
            {
            }

            public function userHasPermission(Uuid $userId, string $permissionCode): bool
            {
                return \in_array($permissionCode, $this->granted, true);
            }
        };
    }

    private function schemaTool(): AgentToolInterface
    {
        return new class implements AgentToolInterface {
            public function name(): string
            {
                return 'create_attribute_stub';
            }

            public function description(): string
            {
                return 'Test-only schema-op stub.';
            }

            public function parametersSchema(): array
            {
                return ['type' => 'object', 'properties' => [], 'required' => []];
            }

            public function requiredPermission(): string
            {
                return 'attribute.write';
            }

            public function kind(): ToolKind
            {
                return ToolKind::Schema;
            }

            public function execute(array $arguments, AgentToolContext $context): array
            {
                return ['ok' => true];
            }
        };
    }

    private function context(Uuid $userId): AgentToolContext
    {
        return new AgentToolContext($userId, new Tenant('alpha', 'Alpha Tenant'));
    }
}
