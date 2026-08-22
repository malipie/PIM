<?php

declare(strict_types=1);

namespace App\Integration\Generic\Presentation\Command;

use App\Integration\Generic\Application\Schedule\SyncScheduleDispatcher;
use App\Integration\Generic\Domain\Repository\SyncBindingRepositoryInterface;
use App\Shared\Domain\Repository\TenantRepositoryInterface;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Tenant\TenantScopeBinder;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * APIC-P3-09 (ADR-0022, epic APIC) — fires every scheduled {@see \App\Integration\Generic\Domain\Entity\SyncBinding}
 * whose `nextRun` has arrived.
 *
 * Driven on a per-minute cadence by {@see \App\Integration\Generic\Infrastructure\Scheduler\SyncSchedule}
 * (Symfony Scheduler → the worker's `scheduler_integration_sync` transport),
 * mirroring how the maintenance schedule runs its sweeps. Each due binding's
 * sync leg(s) are enqueued on the `import` transport by the dispatcher; the
 * actual pull/push runs on the worker.
 *
 * Tenant scoping: FORCE RLS hides `integration_sync_bindings` from the app role
 * until the `app.current_tenant` GUC is set, so the scan runs per tenant — it
 * binds {@see TenantScopeBinder} (context + filter + GUC) AND the Postgres GUC (RLS), exactly
 * like {@see \App\Shared\Infrastructure\Messenger\TenantRlsGucMiddleware} does
 * for workers and like {@see \App\Catalog\Presentation\Command\DetectAttributesDriftCommand}.
 */
#[AsCommand(
    name: 'integration:sync:dispatch-due',
    description: 'Dispatch every scheduled sync binding whose next run time has arrived (all tenants).',
)]
final class DispatchDueSyncBindingsCommand extends Command
{
    public function __construct(
        private readonly TenantScopeBinder $tenantScope,
        private readonly TenantRepositoryInterface $tenants,
        private readonly SyncBindingRepositoryInterface $bindings,
        private readonly SyncScheduleDispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $dispatched = 0;
        foreach ($this->tenants->findAllOrderedByCode() as $tenant) {
            $this->tenantScope->bind($tenant);
            try {
                foreach ($this->bindings->findDueForScheduling($now) as $binding) {
                    $this->dispatcher->dispatch($binding);
                    ++$dispatched;
                }
            } finally {
                $this->tenantScope->release();
            }
        }

        $io->success(\sprintf('Dispatched %d due sync binding(s).', $dispatched));

        return Command::SUCCESS;
    }
}
