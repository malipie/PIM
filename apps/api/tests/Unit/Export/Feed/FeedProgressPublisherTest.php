<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Feed;

use App\Export\Feed\Application\Async\FeedProgressPublisher;
use App\Export\Feed\Domain\Entity\FeedRun;
use App\Export\Feed\Domain\Enum\FeedRunTrigger;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;

/**
 * XMLF-P4-02 — feed progress updates are tenant-scoped, private and complete.
 *
 * The AUD-001 regression class this pins: every Update MUST target the
 * `tenant/{tid}/feeds/{feedId}/runs` topic and carry `private: true` —
 * a public update (or an un-prefixed topic) would broadcast one tenant's
 * regeneration telemetry to every connected client.
 */
final class FeedProgressPublisherTest extends TestCase
{
    private const string BASE = 'https://pim.localhost';

    private RecordingHub $hub;

    private FeedProgressPublisher $publisher;

    private FeedRun $run;

    private Uuid $tenantId;

    protected function setUp(): void
    {
        $this->hub = new RecordingHub();
        $this->publisher = new FeedProgressPublisher($this->hub, self::BASE);

        $tenant = new Tenant('demo', 'Demo Tenant');
        $this->tenantId = $tenant->getId();
        $this->run = new FeedRun(Uuid::v7(), FeedRunTrigger::Manual);
        $this->run->assignTenant($tenant);
    }

    #[Test]
    public function progressPublishesAPrivateTenantScopedUpdate(): void
    {
        $this->publisher->progress($this->run, 400, 1000, 12);

        self::assertCount(1, $this->hub->updates);
        $update = $this->hub->updates[0];

        $expectedTopic = sprintf(
            '%s/tenant/%s/feeds/%s/runs',
            self::BASE,
            $this->tenantId->toRfc4122(),
            $this->run->getFeedProfileId()->toRfc4122(),
        );
        self::assertSame([$expectedTopic], $update->getTopics());
        self::assertTrue($update->isPrivate(), 'AUD-001: a public update would leak cross-tenant');

        $payload = $this->decode($update);
        self::assertSame('progress', $payload['event']);
        self::assertSame($this->run->getId()->toRfc4122(), $payload['run_id']);
        self::assertSame(400, $payload['items_done']);
        self::assertSame(1000, $payload['items_total']);
        self::assertSame(40, $payload['progress_pct']);
        self::assertSame(12, $payload['estimated_seconds_remaining']);
    }

    #[Test]
    public function progressWithoutAKnownTotalKeepsPctNull(): void
    {
        $this->publisher->progress($this->run, 400, null, null);

        $payload = $this->decode($this->hub->updates[0]);
        self::assertNull($payload['items_total']);
        self::assertNull($payload['progress_pct']);
    }

    #[Test]
    public function statusPublishesTheRunLifecycle(): void
    {
        $this->run->markRunning();

        $this->publisher->status($this->run);

        $payload = $this->decode($this->hub->updates[0]);
        self::assertSame('status', $payload['event']);
        self::assertSame('running', $payload['status']);
        self::assertTrue($this->hub->updates[0]->isPrivate());
    }

    #[Test]
    public function hubFailureIsSwallowedTheRegenerationMustNotDie(): void
    {
        $this->hub->failWith = new RuntimeException('hub down');

        $this->publisher->status($this->run);

        self::assertSame([], $this->hub->updates);
    }

    #[Test]
    public function runWithoutTenantIsSkippedNotPublishedUnscoped(): void
    {
        $orphan = new FeedRun(Uuid::v7(), FeedRunTrigger::Manual);

        $this->publisher->status($orphan);

        self::assertSame([], $this->hub->updates, 'an un-scoped topic must never be emitted');
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Update $update): array
    {
        $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        /** @var array<string, mixed> $typed */
        $typed = $payload;

        return $typed;
    }
}

/**
 * Captures published updates; optionally throws to simulate a hub outage.
 */
final class RecordingHub implements HubInterface
{
    /** @var list<Update> */
    public array $updates = [];

    public ?RuntimeException $failWith = null;

    public function getUrl(): string
    {
        return 'https://hub.invalid/.well-known/mercure';
    }

    public function getPublicUrl(): string
    {
        return $this->getUrl();
    }

    public function getProvider(): TokenProviderInterface
    {
        throw new RuntimeException('Not needed in this test.');
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return null;
    }

    public function publish(Update $update): string
    {
        if (null !== $this->failWith) {
            throw $this->failWith;
        }
        $this->updates[] = $update;

        return 'id';
    }
}
