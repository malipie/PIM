<?php

declare(strict_types=1);

namespace App\Integration\Generic\Application\Subscriber;

use App\Catalog\Contracts\BulkGuard;
use App\Catalog\Contracts\Event\ObjectAttributesChanged;
use App\Integration\Generic\Application\Sync\SyncRunScope;
use App\Integration\Generic\Domain\Entity\SyncBinding;
use App\Integration\Generic\Domain\Message\OutboundSyncMessage;
use App\Integration\Generic\Domain\Repository\SyncBindingRepositoryInterface;
use App\Integration\Generic\Domain\Repository\SyncRunRepositoryInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Enqueues an outbound sync when a catalog object changes (APIC-P3-07).
 *
 * Listens to the cross-BC {@see ObjectAttributesChanged} domain event and, for
 * every enabled binding that writes to a remote (outbound/bidirectional) AND
 * targets the changed object's ObjectType, dispatches an {@see OutboundSyncMessage}.
 *
 * Bulk flows (import, bulk edit) run under {@see BulkGuard} and are skipped — a
 * 50k import must not enqueue a sync per row; those paths trigger one run when
 * they finish. The event without an ObjectType id (legacy emitter) is ignored.
 *
 * Anti-loop (#2636): catalog writes performed BY a sync run (inbound upserts,
 * outbound remote-id capture) must not re-enqueue the writing connection's own
 * bindings — {@see SyncRunScope} names the active connection and its bindings
 * are skipped. Other connections' bindings still trigger (A → PIM → B).
 *
 * #2730 — one edit enqueues a FULL sync of the binding's slice, so ten manual
 * edits in a row used to queue ten full pushes of the same (potentially 50k
 * record) set against the remote. Two guards now bound that:
 *   - dedup: a binding with a run already in flight is skipped, because that
 *     run will pick the fresh values up anyway,
 *   - the per-tenant `integration_sync` budget (10/h), which the config has
 *     defined all along while nothing consumed it — the BackoffRestClient
 *     docblock even claimed it was enforced "at the sync-trigger edge".
 * Exhausting the budget skips the ENQUEUE, never the user's edit: the write
 * has already been committed when this subscriber runs.
 */
#[AsMessageHandler]
final readonly class OutboundTriggerSubscriber
{
    /**
     * Matches the handler's redelivery guard window (#2722) so "a run is in
     * flight" means the same thing on both sides of the queue.
     */
    private const string RUNNING_GUARD_WINDOW = '-6 hours';

    public function __construct(
        private BulkGuard $bulkGuard,
        private SyncBindingRepositoryInterface $bindings,
        private SyncRunRepositoryInterface $runs,
        private SyncRunScope $runScope,
        private MessageBusInterface $bus,
        private RateLimiterFactoryInterface $integrationSyncLimiter,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(ObjectAttributesChanged $event): void
    {
        if ($this->bulkGuard->isBulk() || null === $event->objectTypeId) {
            return;
        }

        $objectTypeId = $event->objectTypeId->toRfc4122();
        $activeConnectionId = $this->runScope->activeConnectionId();

        foreach ($this->bindings->findEnabled() as $binding) {
            if (!$binding->getDirection()->writesRemote()) {
                continue;
            }
            if ($binding->getObjectTypeId()->toRfc4122() !== $objectTypeId) {
                continue;
            }
            if (null !== $activeConnectionId && $binding->getConnection()->getId()->equals($activeConnectionId)) {
                continue;
            }
            if ($this->hasRunInFlight($binding)) {
                $this->logger->info('Outbound sync not enqueued — a run of this binding is already in flight.', [
                    'binding' => $binding->getId()->toRfc4122(),
                ]);

                continue;
            }
            if (!$this->integrationSyncLimiter->create($event->tenantId->toRfc4122())->consume()->isAccepted()) {
                $this->logger->warning('Outbound sync not enqueued — the tenant exhausted its integration_sync budget.', [
                    'binding' => $binding->getId()->toRfc4122(),
                    'tenant' => $event->tenantId->toRfc4122(),
                ]);

                continue;
            }

            $this->bus->dispatch(new OutboundSyncMessage($binding->getId(), $event->tenantId));
        }
    }

    private function hasRunInFlight(SyncBinding $binding): bool
    {
        return null !== $this->runs->findRunningByBinding($binding, new DateTimeImmutable(self::RUNNING_GUARD_WINDOW));
    }
}
