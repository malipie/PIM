<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Application\Limits\AgentLimitGuard;
use App\Agent\Application\Llm\AgentLlmClientInterface;
use App\Agent\Application\Llm\AgentLlmResponse;
use App\Agent\Application\Run\AgentLoopRunner;
use App\Agent\Application\Run\AgentSystemPromptBuilder;
use App\Agent\Application\Run\UsageCostCalculator;
use App\Agent\Application\Tool\AgentToolContext;
use App\Agent\Application\Tool\AgentToolInterface;
use App\Agent\Application\Tool\GuardedToolExecutor;
use App\Agent\Application\Tool\PingTool;
use App\Agent\Application\Tool\ToolKind;
use App\Agent\Application\Tool\ToolRegistry;
use App\Agent\Domain\AgentRunStatus;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentMessage;
use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Infrastructure\Anthropic\AgentModelSelector;
use App\Identity\Contracts\Policy\PermissionCheckerInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AGENT-P1-03 (#1955) — the loop against real Postgres with a scripted
 * LLM (deterministic; the SDK adapter is the only network code):
 * grounding turn -> tool trace + messages persisted; write-tool
 * materialization -> awaiting_approval with batch id (autonomy stops
 * BEFORE approval); tool-call and token limits -> run error; LLM
 * failure -> run error, nothing committed.
 */
final class AgentLoopRunnerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public const string BATCH_ID = '0197c3b0-0000-7000-8000-000000000001';

    #[Test]
    public function groundingTurnEndsAwaitingInputWithFullTrace(): void
    {
        [$run, $tenant, $em] = $this->fixture();

        $llm = $this->scriptedLlm([
            new AgentLlmResponse('tool_use', [
                ['type' => 'text', 'text' => 'Sprawdzam katalog.'],
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'ping', 'input' => ['echo' => 'ground']],
            ], 1000, 200),
            new AgentLlmResponse('end_turn', [
                ['type' => 'text', 'text' => 'Znalazłem 0 produktów — doprecyzuj filtr?'],
            ], 1500, 100),
        ]);

        $this->runner($llm, $em)->run($run, $tenant);

        self::assertSame(AgentRunStatus::AwaitingInput, $run->getStatus());
        self::assertSame(2500, $run->getTokensInput());
        self::assertSame(300, $run->getTokensOutput());
        self::assertSame('claude-sonnet-test', $run->getModel());
        self::assertGreaterThan(0.0, (float) $run->getCostUsd());

        $roles = $this->messageRoles($em, $run);
        self::assertSame(
            [AgentMessage::ROLE_USER, AgentMessage::ROLE_ASSISTANT, AgentMessage::ROLE_TOOL, AgentMessage::ROLE_ASSISTANT],
            $roles,
        );
    }

    #[Test]
    public function writeToolMaterializationEndsAtAwaitingApproval(): void
    {
        [$run, $tenant, $em] = $this->fixture();

        $llm = $this->scriptedLlm([
            new AgentLlmResponse('tool_use', [
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'fake_write', 'input' => ['value' => 100]],
            ], 900, 150),
            // AGENT-P8-03 — the loop lets the model close its plan with a
            // summary turn; approval happens at end_turn.
            new AgentLlmResponse('end_turn', [['type' => 'text', 'text' => 'Plan gotowy.']], 100, 20),
        ]);

        $this->runner($llm, $em, [new PingTool(), $this->fakeWriteTool()])->run($run, $tenant);

        self::assertSame(AgentRunStatus::AwaitingApproval, $run->getStatus());
        self::assertSame(self::BATCH_ID, $run->getPendingChangeBatchId()?->toRfc4122());
        self::assertSame(1800, $run->getAffectedCount());
    }

    #[Test]
    public function multiStepPlanAccumulatesIntoOneBatch(): void
    {
        [$run, $tenant, $em] = $this->fixture();

        $recorder = $this->recordingWriteTool();
        $llm = $this->scriptedLlm([
            new AgentLlmResponse('tool_use', [
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'fake_write', 'input' => ['step' => 1]],
            ], 500, 50),
            new AgentLlmResponse('tool_use', [
                ['type' => 'tool_use', 'id' => 'tu_2', 'name' => 'fake_write', 'input' => ['step' => 2]],
            ], 500, 50),
            new AgentLlmResponse('end_turn', [['type' => 'text', 'text' => 'Zbiorczy plan gotowy.']], 100, 20),
        ]);

        $this->runner($llm, $em, [new PingTool(), $recorder])->run($run, $tenant);

        self::assertSame(AgentRunStatus::AwaitingApproval, $run->getStatus());
        self::assertSame(self::BATCH_ID, $run->getPendingChangeBatchId()?->toRfc4122());
        self::assertSame(3600, $run->getAffectedCount(), 'the collective plan sums both steps');
        // The SECOND call inherited the batch id from the first (loop
        // injection) - both steps land in ONE approval.
        self::assertArrayNotHasKey('pending_change_batch_id', $recorder->calls[0]);
        self::assertSame(self::BATCH_ID, $recorder->calls[1]['pending_change_batch_id'] ?? null);
    }

    #[Test]
    public function toolCallLimitEndsInError(): void
    {
        [$run, $tenant, $em] = $this->fixture();

        $manyUses = [];
        for ($i = 0; $i < 11; ++$i) {
            $manyUses[] = ['type' => 'tool_use', 'id' => 'tu_'.$i, 'name' => 'ping', 'input' => []];
        }
        $llm = $this->scriptedLlm([new AgentLlmResponse('tool_use', $manyUses, 500, 50)]);

        $this->runner($llm, $em)->run($run, $tenant);

        self::assertSame(AgentRunStatus::Error, $run->getStatus());
        self::assertStringContainsString('Tool-call limit', (string) $run->getErrorMessage());
    }

    #[Test]
    public function tokenLimitEndsInError(): void
    {
        [$run, $tenant, $em] = $this->fixture();

        $llm = $this->scriptedLlm([
            new AgentLlmResponse('tool_use', [
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'ping', 'input' => []],
            ], 99_000, 2_000),
        ]);

        $this->runner($llm, $em)->run($run, $tenant);

        self::assertSame(AgentRunStatus::Error, $run->getStatus());
        self::assertStringContainsString('Token limit', (string) $run->getErrorMessage());
    }

    #[Test]
    public function llmFailureEndsInErrorWithoutThrowing(): void
    {
        [$run, $tenant, $em] = $this->fixture();

        $llm = new class implements AgentLlmClientInterface {
            public function create(Tenant $tenant, string $model, string $system, array $messages, array $tools, bool $promptCaching = true): AgentLlmResponse
            {
                throw new RuntimeException('backoff exhausted');
            }
        };

        $this->runner($llm, $em)->run($run, $tenant);

        self::assertSame(AgentRunStatus::Error, $run->getStatus());
        self::assertStringContainsString('backoff exhausted', (string) $run->getErrorMessage());
    }

    /**
     * @return array{0: AgentRun, 1: Tenant, 2: EntityManagerInterface}
     */
    private function fixture(): array
    {
        $tenant = new Tenant('alpha', 'Alpha Tenant');
        $em = $this->em();
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        $run = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'ustaw cenę 100 wszystkim bez ceny', ['filter_dsl' => ['price' => 'empty']]);
        $em->persist($run);
        $em->flush();

        return [$run, $tenant, $em];
    }

    /**
     * @param list<AgentLlmResponse> $script
     */
    private function scriptedLlm(array $script): AgentLlmClientInterface
    {
        return new class($script) implements AgentLlmClientInterface {
            private int $turn = 0;

            /** @param list<AgentLlmResponse> $script */
            public function __construct(private readonly array $script)
            {
            }

            public function create(Tenant $tenant, string $model, string $system, array $messages, array $tools, bool $promptCaching = true): AgentLlmResponse
            {
                $response = $this->script[$this->turn] ?? null;
                if (null === $response) {
                    throw new LogicException('LLM called more times than scripted.');
                }
                ++$this->turn;

                return $response;
            }
        };
    }

    /**
     * @param list<AgentToolInterface>|null $tools
     */
    private function runner(AgentLlmClientInterface $llm, EntityManagerInterface $em, ?array $tools = null): AgentLoopRunner
    {
        $checker = new class implements PermissionCheckerInterface {
            public function userHasPermission(Uuid $userId, string $permissionCode): bool
            {
                return true;
            }
        };
        $registry = new ToolRegistry($tools ?? [new PingTool()], $checker);
        $selector = new AgentModelSelector('claude-sonnet-test', 'claude-opus-test');

        return new AgentLoopRunner(
            llm: $llm,
            limits: new AgentLimitGuard($em, 1000, 10_000_000, 10_000.0, 100_000.0),
            registry: $registry,
            executor: new GuardedToolExecutor($registry, $em),
            models: $selector,
            prompts: new AgentSystemPromptBuilder(),
            costs: new UsageCostCalculator($selector, 3.0, 15.0, 5.0, 25.0),
            tenantConfig: new class implements \App\Identity\Contracts\Byok\ByokConfigReaderInterface {
                public function isProactiveScanEnabled(Tenant $tenant): bool
                {
                    return false;
                }

                public function modelOverride(Tenant $tenant): ?string
                {
                    return null;
                }

                public function isPromptCachingEnabled(Tenant $tenant): bool
                {
                    return true;
                }
            },
            entityManager: $em,
            eventBus: new class implements \Symfony\Component\Messenger\MessageBusInterface {
                public function dispatch(object $message, array $stamps = []): \Symfony\Component\Messenger\Envelope
                {
                    return new \Symfony\Component\Messenger\Envelope($message);
                }
            },
            maxToolCallsPerRun: 10,
            maxTokensPerRun: 100_000,
        );
    }

    /**
     * @return AgentToolInterface&object{calls: list<array<string, mixed>>}
     */
    private function recordingWriteTool(): AgentToolInterface
    {
        return new class implements AgentToolInterface {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function name(): string
            {
                return 'fake_write';
            }

            public function description(): string
            {
                return 'Recording write tool.';
            }

            public function parametersSchema(): array
            {
                return ['type' => 'object', 'properties' => [], 'required' => []];
            }

            public function requiredPermission(): string
            {
                return 'object.write';
            }

            public function kind(): ToolKind
            {
                return ToolKind::Write;
            }

            public function execute(array $arguments, AgentToolContext $context): array
            {
                $this->calls[] = $arguments;

                return [
                    'pending_change_batch_id' => AgentLoopRunnerTest::BATCH_ID,
                    'affected_count' => 1800,
                ];
            }
        };
    }

    private function fakeWriteTool(): AgentToolInterface
    {
        return new class implements AgentToolInterface {
            public function name(): string
            {
                return 'fake_write';
            }

            public function description(): string
            {
                return 'Test-only write tool that materializes a proposal.';
            }

            public function parametersSchema(): array
            {
                return ['type' => 'object', 'properties' => [], 'required' => []];
            }

            public function requiredPermission(): string
            {
                return 'object.write';
            }

            public function kind(): ToolKind
            {
                return ToolKind::Write;
            }

            public function execute(array $arguments, AgentToolContext $context): array
            {
                return [
                    'pending_change_batch_id' => AgentLoopRunnerTest::BATCH_ID,
                    'affected_count' => 1800,
                ];
            }
        };
    }

    /**
     * @return list<string>
     */
    private function messageRoles(EntityManagerInterface $em, AgentRun $run): array
    {
        /** @var list<AgentMessage> $messages */
        $messages = $em->createQueryBuilder()
            ->select('m')
            ->from(AgentMessage::class, 'm')
            ->where('m.run = :run')
            ->setParameter('run', $run->getId(), 'uuid')
            ->orderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (AgentMessage $m): string => $m->getRole(), $messages);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
