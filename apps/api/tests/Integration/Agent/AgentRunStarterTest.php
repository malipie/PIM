<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Application\AgentFeatureGuard;
use App\Agent\Application\Run\AgentRunStarter;
use App\Agent\Domain\AgentRunStatus;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Domain\Exception\ActiveRunConflictException;
use App\Agent\Domain\Exception\AgentUnavailableException;
use App\Identity\Contracts\Byok\ByokKeyResolverInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AGENT-P1-03 (#1955) — run start path: guard-first, DB-enforced
 * "1 active run per user" (409), and the real dispatch wire-through
 * (sync transport in tests executes the worker handler inline - with
 * no BYOK key the guard inside the worker marks the run `error`, which
 * is exactly the live keyless behavior).
 */
final class AgentRunStarterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function secondActiveRunForTheSameUserConflicts(): void
    {
        [$tenant, $em] = $this->tenantFixture();
        $starter = new AgentRunStarter($this->guard(true), $em, $this->noopBus());
        $userId = Uuid::v7();

        $first = $starter->start($tenant, $userId, AgentRunSurface::Chat, 'first intent');
        self::assertSame(AgentRunStatus::Planning, $first->getStatus());

        $this->expectException(ActiveRunConflictException::class);
        $starter->start($tenant, $userId, AgentRunSurface::Chat, 'second intent');
    }

    #[Test]
    public function guardRefusalPersistsNothing(): void
    {
        [$tenant, $em] = $this->tenantFixture();
        $starter = new AgentRunStarter($this->guard(false), $em, $this->noopBus());

        try {
            $starter->start($tenant, Uuid::v7(), AgentRunSurface::Chat, 'intent');
            self::fail('Expected AgentUnavailableException');
        } catch (AgentUnavailableException) {
        }

        $count = $em->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(AgentRun::class, 'r')
            ->getQuery()
            ->getSingleScalarResult();
        self::assertSame(0, (int) (\is_scalar($count) ? $count : -1));
    }

    #[Test]
    public function realDispatchRunsWorkerInlineAndKeylessTenantEndsInError(): void
    {
        [$tenant, $em] = $this->tenantFixture();

        // Starter-side guard passes (fake key); the WORKER-side guard is
        // the real container service - the tenant has no BYOK key, so the
        // handler must mark the run error instead of crashing the queue.
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $starter = new AgentRunStarter($this->guard(true), $em, $bus);

        $run = $starter->start($tenant, Uuid::v7(), AgentRunSurface::Chat, 'live wire-through');

        $em->clear();
        $reloaded = $em->createQueryBuilder()
            ->select('r')
            ->from(AgentRun::class, 'r')
            ->where('r.id = :id')
            ->setParameter('id', $run->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        self::assertInstanceOf(AgentRun::class, $reloaded);
        self::assertSame(AgentRunStatus::Error, $reloaded->getStatus());
        self::assertStringContainsString('BYOK', (string) $reloaded->getErrorMessage());
    }

    /**
     * @return array{0: Tenant, 1: EntityManagerInterface}
     */
    private function tenantFixture(): array
    {
        $tenant = new Tenant('alpha', 'Alpha Tenant');
        $em = $this->em();
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        return [$tenant, $em];
    }

    private function guard(bool $hasKey): AgentFeatureGuard
    {
        $resolver = new class($hasKey) implements ByokKeyResolverInterface {
            public function __construct(private readonly bool $hasKey)
            {
            }

            public function resolveKey(Tenant $tenant): ?string
            {
                return $this->hasKey ? 'sk-ant-test' : null;
            }

            public function hasActiveKey(Tenant $tenant): bool
            {
                return $this->hasKey;
            }
        };

        return new AgentFeatureGuard($resolver, agentEnabled: true);
    }

    private function noopBus(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return new Envelope($message, $stamps);
            }
        };
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
