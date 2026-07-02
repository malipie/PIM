<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Domain\AgentRunStatus;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentMessage;
use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Domain\Entity\AgentToolCall;
use App\Agent\Domain\Repository\AgentRunRepositoryInterface;
use App\Agent\Infrastructure\Doctrine\Repository\DoctrineAgentRunRepository;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AGENT-P1-01 (#1953) — orchestration entities against real Postgres:
 * lifecycle round-trip with usage accumulation, tenant isolation
 * (cross-read = 0, DoD), and run-cascade delete of messages/tool calls.
 */
final class AgentEntitiesTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function runLifecycleRoundTripsWithUsageAndProposalLink(): void
    {
        $tenant = $this->createTenant('alpha');
        $this->activateTenantFilter($tenant);
        $em = $this->em();

        $run = new AgentRun(
            userId: Uuid::v7(),
            surface: AgentRunSurface::Chat,
            intent: 'ustaw cenę 100 wszystkim bez ceny',
            context: ['object_type' => 'product', 'filter_dsl' => ['field' => 'price', 'op' => 'is_empty']],
        );
        $run->setModel('claude-sonnet-test');
        $run->addUsage(1200, 340, '0.011400');
        $run->addUsage(800, 120, '0.006200');

        $batchId = Uuid::v7();
        $run->markAwaitingApproval($batchId, 1800);

        $repo = $this->repo();
        $repo->save($run);

        $em->persist(new AgentMessage($run, AgentMessage::ROLE_USER, [['type' => 'text', 'text' => 'hej']]));
        $toolCall = new AgentToolCall($run, 'search', 'read', ['filter' => 'price is empty']);
        $toolCall->complete(['matched' => 1800], rbacChecked: true, durationMs: 42);
        $em->persist($toolCall);
        $em->flush();
        $em->clear();

        $reloaded = $repo->find($run->getId());
        self::assertNotNull($reloaded);
        self::assertSame(AgentRunStatus::AwaitingApproval, $reloaded->getStatus());
        self::assertSame(2000, $reloaded->getTokensInput());
        self::assertSame(460, $reloaded->getTokensOutput());
        self::assertSame('0.017600', $reloaded->getCostUsd());
        self::assertSame(1800, $reloaded->getAffectedCount());
        self::assertTrue($batchId->equals($reloaded->getPendingChangeBatchId() ?? Uuid::v4()));
        self::assertSame('claude-sonnet-test', $reloaded->getModel());
    }

    #[Test]
    public function runsAreIsolatedByTenantFilter(): void
    {
        $alpha = $this->createTenant('alpha');
        $beta = $this->createTenant('beta');
        $repo = $this->repo();

        $this->activateTenantFilter($alpha);
        $alphaRun = new AgentRun(Uuid::v7(), AgentRunSurface::CmdK, 'alpha intent');
        $repo->save($alphaRun);

        $this->em()->clear();
        $this->activateTenantFilter($beta);
        self::assertNull($repo->find($alphaRun->getId()), 'TenantFilter must hide alpha runs from beta context');

        $this->em()->clear();
        $this->activateTenantFilter($alpha);
        self::assertNotNull($repo->find($alphaRun->getId()));
    }

    #[Test]
    public function deletingARunCascadesMessagesAndToolCalls(): void
    {
        $tenant = $this->createTenant('alpha');
        $this->activateTenantFilter($tenant);
        $em = $this->em();

        $run = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'cascade check');
        $em->persist($run);
        $em->persist(new AgentMessage($run, AgentMessage::ROLE_ASSISTANT, [['type' => 'text', 'text' => 'plan']]));
        $em->persist(new AgentToolCall($run, 'ping', 'read', []));
        $em->flush();

        $runId = $run->getId()->toRfc4122();
        $conn = $em->getConnection();
        self::assertSame(1, $this->countRows($conn, 'agent_messages', $runId));

        $conn->executeStatement('DELETE FROM agent_runs WHERE id = ?', [$runId]);
        self::assertSame(0, $this->countRows($conn, 'agent_messages', $runId), 'messages must cascade with the run');
        self::assertSame(0, $this->countRows($conn, 'agent_tool_calls', $runId), 'tool calls must cascade with the run');
    }

    #[Test]
    public function statusGuardsProtectTheApprovalBoundary(): void
    {
        $run = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'guards');

        $this->expectException(LogicException::class);
        // Committing without an accepted proposal is a forbidden shortcut.
        $run->markCommitting(Uuid::v7());
    }

    private function countRows(\Doctrine\DBAL\Connection $conn, string $table, string $runId): int
    {
        $count = $conn->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE agent_run_id = ?', $table), [$runId]);

        return (int) (\is_scalar($count) ? $count : -1);
    }

    private function repo(): AgentRunRepositoryInterface
    {
        // Constructed directly: the repository has no runtime consumer
        // until P1-03, so the compiled container would inline/remove it.
        return new DoctrineAgentRunRepository($this->em());
    }

    private function activateTenantFilter(Tenant $tenant): void
    {
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function createTenant(string $code): Tenant
    {
        $tenant = new Tenant($code, ucfirst($code).' Tenant');
        $em = $this->em();
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }
}
