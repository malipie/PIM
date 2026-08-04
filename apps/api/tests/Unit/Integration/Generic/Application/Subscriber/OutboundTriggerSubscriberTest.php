<?php

declare(strict_types=1);

namespace App\Tests\Unit\Integration\Generic\Application\Subscriber;

use App\Catalog\Contracts\BulkGuard;
use App\Catalog\Contracts\Event\ObjectAttributesChanged;
use App\Integration\Generic\Application\Subscriber\OutboundTriggerSubscriber;
use App\Integration\Generic\Application\Sync\SyncRunScope;
use App\Integration\Generic\Domain\Entity\Connection;
use App\Integration\Generic\Domain\Entity\SyncBinding;
use App\Integration\Generic\Domain\Entity\SyncRun;
use App\Integration\Generic\Domain\Enum\SyncDirection;
use App\Integration\Generic\Domain\Message\OutboundSyncMessage;
use App\Integration\Generic\Domain\Repository\SyncBindingRepositoryInterface;
use App\Integration\Generic\Domain\Repository\SyncRunRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

final class OutboundTriggerSubscriberTest extends TestCase
{
    #[Test]
    public function enqueuesOnlyMatchingOutboundBindings(): void
    {
        $productType = Uuid::v7();
        $categoryType = Uuid::v7();
        $connection = new Connection('idosell', 'IdoSell', 'https://api.idosell.com');

        $bindings = [
            $this->binding($connection, $productType, SyncDirection::Outbound),     // match
            $this->binding($connection, $productType, SyncDirection::Bidirectional), // match
            $this->binding($connection, $productType, SyncDirection::Inbound),       // inbound → no
            $this->binding($connection, $categoryType, SyncDirection::Outbound),     // other type → no
        ];

        $bus = $this->bus();
        $this->subscriber($bus, $bindings, bulk: false)(
            new ObjectAttributesChanged(Uuid::v7(), Uuid::v7(), ['name'], objectTypeId: $productType),
        );

        self::assertCount(2, $bus->dispatched);
        self::assertContainsOnlyInstancesOf(OutboundSyncMessage::class, $bus->dispatched);
    }

    #[Test]
    public function skipsDuringBulk(): void
    {
        $bus = $this->bus();
        $type = Uuid::v7();
        $binding = $this->binding(new Connection('c', 'C', 'https://x'), $type, SyncDirection::Outbound);

        $this->subscriber($bus, [$binding], bulk: true)(
            new ObjectAttributesChanged(Uuid::v7(), Uuid::v7(), [], objectTypeId: $type),
        );

        self::assertSame([], $bus->dispatched);
    }

    #[Test]
    public function skipsBindingsOfTheConnectionWhoseRunIsWriting(): void
    {
        // #2636 anti-loop: remote-id capture during connection A's run must not
        // re-enqueue A's own bindings, while B's bindings still trigger.
        $type = Uuid::v7();
        $active = new Connection('base', 'Base', 'https://api.baselinker.com');
        $other = new Connection('shop', 'Shop', 'https://shop.example');
        $bindings = [
            $this->binding($active, $type, SyncDirection::Outbound), // writing → skip
            $this->binding($other, $type, SyncDirection::Outbound),  // other conn → enqueue
        ];

        $scope = new SyncRunScope();
        $scope->enter($active->getId());
        $bus = $this->bus();
        $this->subscriber($bus, $bindings, bulk: false, scope: $scope)(
            new ObjectAttributesChanged(Uuid::v7(), Uuid::v7(), ['base_product_id'], objectTypeId: $type),
        );

        self::assertCount(1, $bus->dispatched);

        // Outside a run everything triggers again.
        $scope->leave();
        $bus2 = $this->bus();
        $this->subscriber($bus2, $bindings, bulk: false, scope: $scope)(
            new ObjectAttributesChanged(Uuid::v7(), Uuid::v7(), ['name'], objectTypeId: $type),
        );
        self::assertCount(2, $bus2->dispatched);
    }

    #[Test]
    public function skipsWhenEventHasNoObjectType(): void
    {
        $bus = $this->bus();
        $binding = $this->binding(new Connection('c', 'C', 'https://x'), Uuid::v7(), SyncDirection::Outbound);

        $this->subscriber($bus, [$binding], bulk: false)(
            new ObjectAttributesChanged(Uuid::v7(), Uuid::v7(), []),
        );

        self::assertSame([], $bus->dispatched);
    }

    #[Test]
    public function doesNotEnqueueWhenARunOfTheBindingIsAlreadyInFlight(): void
    {
        // #2730 — the in-flight run will pick the fresh values up anyway, so a
        // second full push of the same slice is pure waste against the remote.
        $type = Uuid::v7();
        $connection = new Connection('c', 'C', 'https://x');
        $binding = $this->binding($connection, $type, SyncDirection::Outbound);
        $bus = $this->bus();

        $this->subscriber($bus, [$binding], bulk: false, runInFlight: new SyncRun($binding, SyncDirection::Outbound))(
            new ObjectAttributesChanged(Uuid::v7(), Uuid::v7(), ['name'], objectTypeId: $type),
        );

        self::assertSame([], $bus->dispatched);
    }

    #[Test]
    public function stopsEnqueueingOnceTheTenantBudgetIsExhausted(): void
    {
        // #2730 — the integration_sync limiter (10/h/tenant) was configured but
        // never consumed; a burst of manual edits could flood the remote.
        $type = Uuid::v7();
        $connection = new Connection('c', 'C', 'https://x');
        $binding = $this->binding($connection, $type, SyncDirection::Outbound);
        $tenantId = Uuid::v7();
        $limiter = new RateLimiterFactory(
            ['id' => 'integration_sync_test', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );

        $bus = $this->bus();
        $subscriber = $this->subscriber($bus, [$binding], bulk: false, limiter: $limiter);
        foreach (range(1, 5) as $ignored) {
            $subscriber(new ObjectAttributesChanged(Uuid::v7(), $tenantId, ['name'], objectTypeId: $type));
        }

        self::assertCount(2, $bus->dispatched, 'the tenant budget caps how many runs a burst can enqueue');
    }

    private function binding(Connection $connection, Uuid $objectTypeId, SyncDirection $direction): SyncBinding
    {
        return new SyncBinding($connection, $objectTypeId, $direction);
    }

    /**
     * @param list<SyncBinding> $bindings
     */
    private function subscriber(
        RecordingMessageBus $bus,
        array $bindings,
        bool $bulk,
        ?SyncRunScope $scope = null,
        ?SyncRun $runInFlight = null,
        ?RateLimiterFactoryInterface $limiter = null,
    ): OutboundTriggerSubscriber {
        $guard = $this->createStub(BulkGuard::class);
        $guard->method('isBulk')->willReturn($bulk);

        $repo = $this->createStub(SyncBindingRepositoryInterface::class);
        $repo->method('findEnabled')->willReturn($bindings);

        $runs = $this->createStub(SyncRunRepositoryInterface::class);
        $runs->method('findRunningByBinding')->willReturn($runInFlight);

        return new OutboundTriggerSubscriber(
            $guard,
            $repo,
            $runs,
            $scope ?? new SyncRunScope(),
            $bus,
            $limiter ?? self::unlimitedLimiter(),
            new NullLogger(),
        );
    }

    /** A budget generous enough that only the tests that ask for a tight one see 429s. */
    private static function unlimitedLimiter(): RateLimiterFactoryInterface
    {
        return new RateLimiterFactory(
            ['id' => 'integration_sync_test', 'policy' => 'fixed_window', 'limit' => 1000, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
    }

    private function bus(): RecordingMessageBus
    {
        return new RecordingMessageBus();
    }
}
