<?php

declare(strict_types=1);

namespace App\Dashboard\Presentation\Command;

use App\Dashboard\Application\Query\DashboardSummaryQuery;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Tenant\TenantScopeBinder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;

/**
 * DASH-05 (#2257, ADR-0026) — daily `dashboard_snapshots` writer: one
 * upsert per active tenant with the same aggregates the summary endpoint
 * serves (single source of the math: {@see DashboardSummaryQuery::aggregates}).
 *
 * Cross-tenant iteration mirrors the TenantPurger pattern: the RLS GUC
 * `app.current_tenant` is pinned per tenant for the duration of its
 * statements (the worker connects as `pim_app`, and dashboard_snapshots
 * has FORCE RLS) and reset in a finally. The explicit tenant_id VALUES
 * are defence in depth on top of RLS; under the test schema (no RLS
 * policies) they are the only guard — exactly what the tests assert.
 *
 * Scheduled daily at 03:45 UTC via MaintenanceSchedule; `--dry-run`
 * prints the would-be rows without writing.
 */
#[AsCommand(
    name: 'pim:dashboard:snapshot',
    description: 'Upsert today\'s dashboard KPI snapshot for every active tenant.',
)]
final class DashboardSnapshotCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantScopeBinder $tenantScope,
        private readonly DashboardSummaryQuery $query,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the would-be snapshots without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = true === $input->getOption('dry-run');
        $connection = $this->entityManager->getConnection();

        /** @var list<Tenant> $tenants */
        $tenants = $this->entityManager->getRepository(Tenant::class)->findAll();
        $written = 0;

        foreach ($tenants as $tenant) {
            if (!$tenant->isActive()) {
                continue;
            }
            $tenantId = $tenant->getId()->toRfc4122();

            try {
                $this->tenantScope->bind($tenant);

                $aggregates = $this->query->aggregates();

                $perChannel = [];
                foreach ($aggregates->channels as $channel) {
                    $perChannel[$channel->channelCode] = [
                        'avgPct' => $channel->avgPct,
                        'readyCount' => $channel->readyCount,
                    ];
                }

                if ($dryRun) {
                    $io->writeln(\sprintf(
                        '[dry-run] %s: total=%d ready=%d avg=%d%% channels=%d',
                        $tenant->getCode(),
                        $aggregates->productsTotal,
                        $aggregates->publishReadyCount,
                        $aggregates->avgCompletenessPct,
                        \count($perChannel),
                    ));
                    ++$written;
                    continue;
                }

                // tenant-safe: explicit tenant_id VALUE + RLS GUC pinned above.
                $connection->executeStatement(
                    'INSERT INTO dashboard_snapshots '
                    .'(id, tenant_id, snapshot_date, products_total, publish_ready_count, '
                    .'avg_completeness_pct, per_channel, created_at) '
                    .'VALUES (:id, :tenant, CURRENT_DATE, :total, :ready, :avg, :per_channel, NOW()) '
                    .'ON CONFLICT (tenant_id, snapshot_date) DO UPDATE SET '
                    .'products_total = EXCLUDED.products_total, '
                    .'publish_ready_count = EXCLUDED.publish_ready_count, '
                    .'avg_completeness_pct = EXCLUDED.avg_completeness_pct, '
                    .'per_channel = EXCLUDED.per_channel, '
                    .'created_at = EXCLUDED.created_at',
                    [
                        'id' => Uuid::v7()->toRfc4122(),
                        'tenant' => $tenantId,
                        'total' => $aggregates->productsTotal,
                        'ready' => $aggregates->publishReadyCount,
                        'avg' => $aggregates->avgCompletenessPct,
                        'per_channel' => json_encode($perChannel, JSON_THROW_ON_ERROR),
                    ],
                );
                ++$written;
            } finally {
                $this->tenantScope->release();
            }
        }

        $io->success(\sprintf(
            $dryRun ? '%d tenant snapshot(s) computed (dry-run, nothing written).' : '%d tenant snapshot(s) upserted.',
            $written,
        ));

        return Command::SUCCESS;
    }
}
