<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Application\Limits\AgentLimitGuard;
use App\Agent\Application\Llm\AgentLlmClientInterface;
use App\Agent\Application\Llm\AgentLlmResponse;
use App\Agent\Application\Run\AgentLoopRunner;
use App\Agent\Application\Run\AgentSystemPromptBuilder;
use App\Agent\Application\Run\UsageCostCalculator;
use App\Agent\Application\Tool\CreateAttributesFromSchemaTool;
use App\Agent\Application\Tool\GuardedToolExecutor;
use App\Agent\Application\Tool\PingTool;
use App\Agent\Application\Tool\ToolRegistry;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Infrastructure\Anthropic\AgentModelSelector;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Identity\Contracts\Policy\PermissionCheckerInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AGENT-P5-03 (#1972) — per-kind model selection end-to-end (PRD
 * §10.1): a run whose AVAILABLE tools include kind=schema (the user
 * holds a modeling permission) rides the Opus-tier model; a run whose
 * user only has value/read permissions rides the Sonnet-tier default.
 * The choice is declarative (registry kinds -> AgentModelSelector, both
 * configured in one place) and the used model persists on
 * agent_runs.model.
 *
 * Deliberate semantics: availability decides, not per-run usage — the
 * model must be picked BEFORE the first LLM call, and a modeling-
 * capable user's conversation can turn to schema at any point.
 */
final class ModelSelectionTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function schemaCapableUserRidesOpus(): void
    {
        [$run, $tenant, $em] = $this->fixture();

        $this->runner($em, allowModeling: true)->run($run, $tenant);

        self::assertSame('claude-opus-test', $run->getModel());
        $stored = $em->getConnection()->fetchOne('SELECT model FROM agent_runs WHERE id = :id', ['id' => $run->getId()->toRfc4122()]);
        self::assertSame('claude-opus-test', $stored, 'agent_runs.model must persist the used model');
    }

    #[Test]
    public function valueOnlyUserRidesSonnet(): void
    {
        [$run, $tenant, $em] = $this->fixture();

        $this->runner($em, allowModeling: false)->run($run, $tenant);

        self::assertSame('claude-sonnet-test', $run->getModel());
        $stored = $em->getConnection()->fetchOne('SELECT model FROM agent_runs WHERE id = :id', ['id' => $run->getId()->toRfc4122()]);
        self::assertSame('claude-sonnet-test', $stored);
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

        $run = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'do something');
        $em->persist($run);
        $em->flush();

        return [$run, $tenant, $em];
    }

    private function runner(EntityManagerInterface $em, bool $allowModeling): AgentLoopRunner
    {
        $checker = new class($allowModeling) implements PermissionCheckerInterface {
            public function __construct(private readonly bool $allowModeling)
            {
            }

            public function userHasPermission(Uuid $userId, string $permissionCode): bool
            {
                if (str_starts_with($permissionCode, 'modeling.')) {
                    return $this->allowModeling;
                }

                return true;
            }
        };

        // The real schema tool declares kind=schema; ping is kind=read.
        $registry = new ToolRegistry([
            new PingTool(),
            new CreateAttributesFromSchemaTool(self::getContainer()->get(PendingChangesPort::class)),
        ], $checker);
        $selector = new AgentModelSelector('claude-sonnet-test', 'claude-opus-test');

        $llm = new class implements AgentLlmClientInterface {
            public function create(Tenant $tenant, string $model, string $system, array $messages, array $tools): AgentLlmResponse
            {
                return new AgentLlmResponse('end_turn', [['type' => 'text', 'text' => 'ok']], 10, 5);
            }
        };

        return new AgentLoopRunner(
            llm: $llm,
            limits: new AgentLimitGuard($em, 1000, 10_000_000, 10_000.0, 100_000.0),
            registry: $registry,
            executor: new GuardedToolExecutor($registry, $em),
            models: $selector,
            prompts: new AgentSystemPromptBuilder(),
            costs: new UsageCostCalculator($selector, 3.0, 15.0, 5.0, 25.0),
            entityManager: $em,
            maxToolCallsPerRun: 10,
            maxTokensPerRun: 100_000,
        );
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
