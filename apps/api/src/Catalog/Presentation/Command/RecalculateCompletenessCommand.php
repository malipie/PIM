<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Command;

use App\Catalog\Application\AttributesIndexedRebuilder;
use App\Catalog\Application\BulkContext;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Shared\Domain\Repository\TenantRepositoryInterface;
use App\Shared\Infrastructure\Tenant\TenantScopeBinder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * UI-02.1 (#291) — one-shot backfill of the new `objects.completeness_pct`
 * column after the migration. Iterates `objects` per tenant + kind and
 * re-runs {@see AttributesIndexedRebuilder} to populate the cached
 * percentage from the canonical `ObjectValue` rows.
 *
 * Memory shape mirrors {@see \App\Search\Presentation\Command\SearchReindexCommand}:
 * Doctrine `iterate()` + `EntityManager::clear()` every 200 rows so the
 * worker peak stays bounded for 50k SKU runs.
 */
#[AsCommand(
    name: 'pim:catalog:recalculate-completeness',
    description: 'Recompute objects.completeness_pct for every CatalogObject of the given kind/tenant.',
)]
final class RecalculateCompletenessCommand extends Command
{
    private const int FLUSH_EVERY = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttributesIndexedRebuilder $rebuilder,
        private readonly BulkContext $bulkContext,
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantScopeBinder $tenantBinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'kind',
                null,
                InputOption::VALUE_REQUIRED,
                'ObjectKind code (`product`, `category`, `asset`, `brand`) or `all`.',
                'product',
            )
            ->addOption(
                'tenant',
                null,
                InputOption::VALUE_REQUIRED,
                'Tenant slug to scope the recompute. Required.',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Iterate but do not flush — counts rows that would change.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tenantCode = $input->getOption('tenant');
        if (!\is_string($tenantCode) || '' === $tenantCode) {
            $io->error('--tenant is required.');

            return Command::INVALID;
        }

        // #2466 — the RLS policies on `objects` read the `app.current_tenant`
        // GUC, which console commands never set; without binding, the query
        // below sees zero rows and the backfill silently no-ops.
        $tenant = $this->tenants->findByCode($tenantCode);
        if (null === $tenant) {
            $io->error(\sprintf('Tenant "%s" not found.', $tenantCode));

            return Command::INVALID;
        }
        $this->tenantBinder->bind($tenant);

        /** @var string $kindOption */
        $kindOption = $input->getOption('kind');
        $kinds = 'all' === $kindOption
            ? [ObjectKind::Product, ObjectKind::Category, ObjectKind::Asset]
            : [ObjectKind::from($kindOption)];
        /** @var bool $dryRun */
        $dryRun = $input->getOption('dry-run');

        // Bulk path: short-circuit the synchronous AttributesIndexedSyncListener
        // so we do not double-rebuild on every flush during the backfill.
        $this->bulkContext->setBulk(true);

        $totalChanged = 0;
        try {
            foreach ($kinds as $kind) {
                $io->section(\sprintf('Recomputing kind=%s for tenant=%s', $kind->value, $tenantCode));

                $query = $this->em->createQuery(
                    'SELECT o FROM '.CatalogObject::class.' o'
                    .' JOIN o.tenant t'
                    .' WHERE o.kind = :kind AND t.code = :tenantCode'
                );
                $query->setParameter('kind', $kind->value);
                $query->setParameter('tenantCode', $tenantCode);

                $processed = 0;
                $changed = 0;
                /** @var iterable<int, CatalogObject> $iterable */
                $iterable = $query->toIterable();
                foreach ($iterable as $object) {
                    $previousPct = $object->getCompletenessPct();
                    $this->rebuilder->rebuild($object);
                    if ($object->getCompletenessPct() !== $previousPct) {
                        ++$changed;
                    }

                    if (0 === ++$processed % self::FLUSH_EVERY) {
                        if (!$dryRun) {
                            $this->em->flush();
                        }
                        $this->em->clear();
                    }
                }

                if (!$dryRun) {
                    $this->em->flush();
                }
                $this->em->clear();

                $io->writeln(\sprintf(
                    '  %d processed, %d completeness_pct changes %s',
                    $processed,
                    $changed,
                    $dryRun ? '(dry-run, not persisted)' : 'persisted',
                ));
                $totalChanged += $changed;
            }
        } finally {
            $this->bulkContext->setBulk(false);
            $this->tenantBinder->release();
        }

        $io->success(\sprintf('Done. %d row(s) updated.', $totalChanged));

        return Command::SUCCESS;
    }
}
