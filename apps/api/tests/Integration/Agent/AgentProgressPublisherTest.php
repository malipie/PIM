<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Application\Run\AgentProgressPublisher;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Mercure\MercureSubscribeTopics;
use App\Tests\Support\InMemoryMercureHub;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;

/**
 * AGENT-P1-05 (#1957) — run updates go to the two tenant-scoped topics
 * as PRIVATE updates (AUD-001 model: the hub only delivers them to a
 * JWT authorised for that tenant's prefix), the subscribe claim covers
 * the agent topics, and a hub failure never aborts the caller.
 */
final class AgentProgressPublisherTest extends TestCase
{
    private const string BASE = 'https://pim.localhost';

    #[Test]
    public function statusPublishesPrivateUpdatesToRunAndUserTopics(): void
    {
        $hub = new InMemoryMercureHub();
        $publisher = new AgentProgressPublisher($hub, self::BASE);

        [$run, $tenantId] = $this->makeRun();
        $publisher->status($run);
        $publisher->progress($run, 'materializing');
        $publisher->delta($run, 'Gotowe', 1);

        $updates = $hub->getCapturedUpdates();
        self::assertCount(6, $updates);

        $topics = [];
        foreach ($updates as $captured) {
            $first = $captured->getTopics()[0] ?? null;
            $topics[] = \is_string($first) ? $first : '';
        }
        $runTopic = MercureSubscribeTopics::agentRun($tenantId, self::BASE, $run->getId()->toRfc4122());
        $userTopic = MercureSubscribeTopics::agentUser($tenantId, self::BASE, $run->getUserId()->toRfc4122());
        self::assertSame([$runTopic, $userTopic, $runTopic, $userTopic, $runTopic, $userTopic], $topics);

        foreach ($updates as $update) {
            self::assertTrue($update->isPrivate(), 'AUD-001: agent updates must be private');
        }

        $payload = json_decode($updates[0]->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('status', $payload['event']);
        self::assertSame('planning', $payload['status']);

        $progress = json_decode($updates[2]->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($progress);
        self::assertSame('materializing', $progress['phase']);

        $delta = json_decode($updates[4]->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($delta);
        self::assertSame('delta', $delta['event']);
        self::assertSame('Gotowe', $delta['delta']);
        self::assertSame(1, $delta['sequence']);
    }

    #[Test]
    public function subscribeClaimCoversAgentTopicsWithinTenantPrefixOnly(): void
    {
        $tenantId = Uuid::v7();
        $claim = MercureSubscribeTopics::forTenant($tenantId, self::BASE);
        $prefix = MercureSubscribeTopics::tenantPrefix($tenantId, self::BASE);

        self::assertContains($prefix.'/agent-runs/{id}', $claim);
        self::assertContains($prefix.'/agent-runs/user/{id}', $claim);
        foreach ($claim as $topic) {
            self::assertStringStartsWith($prefix.'/', $topic, 'every claim entry must stay pinned to the tenant prefix');
        }
    }

    #[Test]
    public function hubFailureIsSwallowed(): void
    {
        $hub = new class implements HubInterface {
            public function getUrl(): string
            {
                return 'http://localhost/.well-known/mercure';
            }

            public function getPublicUrl(): string
            {
                return $this->getUrl();
            }

            public function getProvider(): TokenProviderInterface
            {
                return new StaticTokenProvider('t');
            }

            public function getFactory(): ?TokenFactoryInterface
            {
                return null;
            }

            public function publish(Update $update): string
            {
                throw new RuntimeException('hub down');
            }
        };
        $publisher = new AgentProgressPublisher($hub, self::BASE);

        [$run] = $this->makeRun();
        $publisher->status($run);
        $this->addToAssertionCount(1);
    }

    /**
     * @return array{0: AgentRun, 1: Uuid}
     */
    private function makeRun(): array
    {
        $tenant = new Tenant('alpha', 'Alpha Tenant');
        $run = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'intent');
        $run->assignTenant($tenant);

        return [$run, $tenant->getId()];
    }
}
