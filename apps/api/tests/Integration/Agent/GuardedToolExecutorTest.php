<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Application\Tool\AgentToolContext;
use App\Agent\Application\Tool\AgentToolInterface;
use App\Agent\Application\Tool\GuardedToolExecutor;
use App\Agent\Application\Tool\PingTool;
use App\Agent\Application\Tool\ToolKind;
use App\Agent\Application\Tool\ToolRegistry;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Domain\Entity\AgentToolCall;
use App\Identity\Contracts\Policy\PermissionCheckerInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use const JSON_THROW_ON_ERROR;

/**
 * AGENT-P1-02 (#1954, SEC failing-test-first) — every tool call runs
 * through the guarded executor: allowed calls persist a complete
 * technical trace (rbac_checked, duration, compact summary); a denial
 * becomes a "forbidden" tool_result for the model (not a loop crash)
 * and the ATTEMPT is recorded; a crashing tool leaks no internals.
 */
final class GuardedToolExecutorTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function allowedCallPersistsTraceWithRbacChecked(): void
    {
        [$run, $context, $em] = $this->fixture();
        $executor = new GuardedToolExecutor($this->registry(['object.read']), $em);

        $result = $executor->execute($run, 'ping', ['echo' => 'hi'], $context);

        self::assertFalse($result['is_error']);
        self::assertTrue($result['content']['pong']);

        $calls = $this->callsFor($em, $run);
        self::assertCount(1, $calls);
        self::assertSame('ping', $calls[0]->getToolName());
        self::assertSame('read', $calls[0]->getKind());
        self::assertTrue($calls[0]->isRbacChecked());
        self::assertNotNull($calls[0]->getDurationMs());
        self::assertSame(['pong' => true, 'tenant' => 'alpha', 'echo' => 'hi'], $calls[0]->getResultSummary());
    }

    #[Test]
    public function forbiddenCallBecomesToolResultAndAttemptIsRecorded(): void
    {
        [$run, $context, $em] = $this->fixture();
        // No permissions at all: ping is outside the user's scope.
        $executor = new GuardedToolExecutor($this->registry([]), $em);

        $result = $executor->execute($run, 'ping', [], $context);

        self::assertTrue($result['is_error']);
        self::assertSame('forbidden', $result['content']['error']);

        $calls = $this->callsFor($em, $run);
        self::assertCount(1, $calls, 'the denied ATTEMPT must be recorded');
        self::assertTrue($calls[0]->isRbacChecked());
        self::assertSame(['forbidden' => true], $calls[0]->getResultSummary());
    }

    #[Test]
    public function crashingToolLeaksNoInternalsToTheModel(): void
    {
        [$run, $context, $em] = $this->fixture();
        $crashing = new class implements AgentToolInterface {
            public function name(): string
            {
                return 'crashy';
            }

            public function description(): string
            {
                return 'test';
            }

            public function parametersSchema(): array
            {
                return ['type' => 'object', 'properties' => [], 'required' => []];
            }

            public function requiredPermission(): string
            {
                return 'object.read';
            }

            public function kind(): ToolKind
            {
                return ToolKind::Read;
            }

            public function execute(array $arguments, AgentToolContext $context): array
            {
                throw new RuntimeException('internal secret marker xyzzy-internal-detail');
            }
        };
        $registry = new ToolRegistry([$crashing], $this->checker(['object.read']));
        $executor = new GuardedToolExecutor($registry, $em);

        $result = $executor->execute($run, 'crashy', [], $context);

        self::assertTrue($result['is_error']);
        self::assertSame('tool_failed', $result['content']['error']);
        self::assertStringNotContainsString('xyzzy-internal', json_encode($result, JSON_THROW_ON_ERROR), 'internals must not reach the model');

        $calls = $this->callsFor($em, $run);
        self::assertSame(['failed' => true], $calls[0]->getResultSummary());
    }

    /**
     * @return array{0: AgentRun, 1: AgentToolContext, 2: EntityManagerInterface}
     */
    private function fixture(): array
    {
        $tenant = new Tenant('alpha', 'Alpha Tenant');
        $em = $this->em();
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        $userId = Uuid::v7();
        $run = new AgentRun($userId, AgentRunSurface::Chat, 'test intent');
        $em->persist($run);
        $em->flush();

        return [$run, new AgentToolContext($userId, $tenant), $em];
    }

    /**
     * @param list<string> $granted
     */
    private function registry(array $granted): ToolRegistry
    {
        return new ToolRegistry([new PingTool()], $this->checker($granted));
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

    /**
     * @return list<AgentToolCall>
     */
    private function callsFor(EntityManagerInterface $em, AgentRun $run): array
    {
        /** @var list<AgentToolCall> $calls */
        $calls = $em->createQueryBuilder()
            ->select('c')
            ->from(AgentToolCall::class, 'c')
            ->where('c.run = :run')
            ->setParameter('run', $run->getId(), 'uuid')
            ->getQuery()
            ->getResult();

        return $calls;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
