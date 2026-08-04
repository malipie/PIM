<?php

declare(strict_types=1);

namespace App\Integration\Generic\Application\Handler;

use App\Integration\Generic\Application\Sync\OutboundSyncRunner;
use App\Integration\Generic\Domain\Entity\SyncBinding;
use App\Integration\Generic\Domain\Message\OutboundSyncMessage;
use App\Integration\Generic\Domain\Repository\SyncBindingRepositoryInterface;
use App\Integration\Generic\Domain\Repository\SyncRunRepositoryInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs an outbound sync when an {@see OutboundSyncMessage} arrives (APIC-P3-06).
 *
 * Tenant context + RLS GUC are restored by the shared worker middleware before
 * this runs (the message is {@see \App\Shared\Application\TenantAwareMessage}),
 * so the binding loads tenant-scoped. A binding deleted between dispatch and
 * delivery is a no-op. The push loop + per-record flush live in the runner.
 */
#[AsMessageHandler]
final readonly class OutboundSyncHandler
{
    /**
     * Redelivery guard window (#2722): a run started within this window and
     * still `running` blocks a second concurrent run of the same binding. Wider
     * than the transport's 4h redeliver_timeout so a redelivered message of a
     * genuinely long push cannot start a duplicate; short enough that a run
     * orphaned by a hard worker kill frees the binding the same day.
     */
    private const string RUNNING_GUARD_WINDOW = '-6 hours';

    public function __construct(
        private SyncBindingRepositoryInterface $bindings,
        private SyncRunRepositoryInterface $runs,
        private OutboundSyncRunner $runner,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(OutboundSyncMessage $message): void
    {
        $binding = $this->bindings->findById($message->bindingId);
        if (!$binding instanceof SyncBinding) {
            $this->logger->info('Outbound sync skipped — binding no longer exists.', [
                'binding' => $message->bindingId->toRfc4122(),
            ]);

            return;
        }

        $active = $this->runs->findRunningByBinding($binding, new DateTimeImmutable(self::RUNNING_GUARD_WINDOW));
        if (null !== $active) {
            $this->logger->info('Outbound sync skipped — a run of this binding is already in progress.', [
                'binding' => $message->bindingId->toRfc4122(),
                'active_run' => $active->getId()->toRfc4122(),
            ]);

            return;
        }

        $this->runner->run($binding, $message->dryRun);
    }
}
