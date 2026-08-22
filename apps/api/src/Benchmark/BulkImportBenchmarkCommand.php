<?php

declare(strict_types=1);

namespace App\Benchmark;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Repository\DoctrineTenantRepository;
use App\Shared\Infrastructure\Tenant\TenantScopeBinder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Worker-memory benchmark for the FrankenPHP / Doctrine identity-map pattern.
 *
 * Inserts N synthetic CatalogObject rows in batches and reports peak
 * memory usage. The loop mirrors the {@see \App\Shared\Application\AbstractBatchHandler}
 * contract: `flush()` followed by `clear()` every `batchSize` rows. A
 * green run (peak < 256 MiB on 5 000 inserts) is the Sprint 0 gate that
 * proves Messenger handlers built on the abstract base will survive a
 * 50k-SKU import without OOM-ing the worker (ticket 0.0.13, ryzyko R-25,
 * sekcja 3.10 architektury).
 *
 * Updated for #33: legacy `Product` entity is gone; the benchmark now
 * inserts `CatalogObject` rows of `kind='product'` against the tenant's
 * built-in product ObjectType.
 *
 * `--no-clear` reproduces the leak so the operator can witness the
 * pattern's value: memory grows roughly linearly with row count when
 * `clear()` is skipped. After the run the command deletes everything it
 * created unless `--keep` is passed.
 */
#[AsCommand(
    name: 'pim:benchmark:bulk-import',
    description: 'Insert N CatalogObject rows in batches and report peak memory usage (ticket 0.0.13).',
)]
final class BulkImportBenchmarkCommand extends Command
{
    private const int MEMORY_THRESHOLD_BYTES = 256 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DoctrineTenantRepository $tenantRepository,
        private readonly ObjectTypeRepositoryInterface $objectTypeRepository,
        private readonly TenantScopeBinder $tenantScope,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'Number of rows to insert', '5000')
            ->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'Flush + clear cadence', '200')
            ->addOption('tenant', 't', InputOption::VALUE_REQUIRED, 'Tenant code to assign rows to', 'acme')
            ->addOption('no-clear', null, InputOption::VALUE_NONE, 'Skip EntityManager::clear() — reproduces the leak')
            ->addOption('keep', null, InputOption::VALUE_NONE, 'Keep inserted rows after the run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = (int) $input->getOption('count');
        $batchSize = (int) $input->getOption('batch-size');
        /** @var string $tenantCode */
        $tenantCode = $input->getOption('tenant');
        /** @var bool $noClear */
        $noClear = $input->getOption('no-clear');
        /** @var bool $keep */
        $keep = $input->getOption('keep');

        if ($count < 1) {
            $io->error('--count must be at least 1.');

            return Command::INVALID;
        }
        if ($batchSize < 1) {
            $io->error('--batch-size must be at least 1.');

            return Command::INVALID;
        }

        $tenant = $this->tenantRepository->findOneBy(['code' => $tenantCode]);
        if (!$tenant instanceof Tenant) {
            $io->error(\sprintf('Tenant "%s" not found. Run `doctrine:fixtures:load` first.', $tenantCode));

            return Command::FAILURE;
        }

        $tenantId = $tenant->getId();
        $this->tenantScope->bind($tenant);

        $productType = $this->objectTypeRepository->findBuiltInByKind(ObjectKind::Product, $tenant);
        if (!$productType instanceof ObjectType) {
            $io->error(\sprintf('Built-in product ObjectType missing for tenant "%s". Run `doctrine:fixtures:load`.', $tenantCode));

            return Command::FAILURE;
        }
        $productTypeId = $productType->getId();

        $skuPrefix = \sprintf('bench-%s-', \dechex(\random_int(0x100000, 0xFFFFFF)));

        $io->section(\sprintf(
            'Inserting %d CatalogObject rows (batch=%d, clear=%s) for tenant "%s" with SKU prefix "%s"',
            $count,
            $batchSize,
            $noClear ? 'OFF' : 'ON',
            $tenantCode,
            $skuPrefix,
        ));

        $startedAt = \microtime(true);
        $startMemory = \memory_get_usage(true);

        for ($i = 1; $i <= $count; ++$i) {
            $sku = \sprintf('%s%05d', $skuPrefix, $i);
            $object = new CatalogObject($productType, $sku);
            $object->forceStatus(CatalogObject::STATUS_PUBLISHED);
            $object->updateAttributeIndex([
                'sku' => $sku,
                'name' => \sprintf('Benchmark product %05d', $i),
                'brand' => 'Benchmark Co',
                'description' => \sprintf('Synthetic row %d generated by pim:benchmark:bulk-import.', $i),
            ]);

            $this->entityManager->persist($object);

            if (0 === $i % $batchSize) {
                $this->entityManager->flush();
                if (!$noClear) {
                    $this->entityManager->clear();
                    // After clear(), every entity we held is detached.
                    // Re-fetch tenant + product type so the next batch's
                    // TenantAssignmentListener and CatalogObject FK both
                    // see managed instances (sekcja 3.10 architektury).
                    $tenant = $this->tenantRepository->find($tenantId);
                    \assert($tenant instanceof Tenant);
                    $this->tenantScope->bind($tenant);
                    $productType = $this->entityManager->find(ObjectType::class, $productTypeId);
                    \assert($productType instanceof ObjectType);
                }
            }
        }

        $this->entityManager->flush();
        if (!$noClear) {
            $this->entityManager->clear();
        }

        $insertDuration = \microtime(true) - $startedAt;
        $endMemory = \memory_get_usage(true);
        $peakMemory = \memory_get_peak_usage(true);

        $io->success(\sprintf('Inserted %d rows in %.2fs.', $count, $insertDuration));

        // Streaming verification phase. Query::toIterable() walks the result set
        // without ever materialising every row into the identity map — the same
        // pattern bulk export workers will use in epik 0.4. We `clear()` per
        // batch boundary here too so the verification itself does not leak.
        $verified = 0;
        $verifyStartedAt = \microtime(true);
        $verifyQuery = $this->entityManager->createQuery(
            'SELECT o FROM '.CatalogObject::class.' o WHERE o.code LIKE :prefix',
        )->setParameter('prefix', $skuPrefix.'%');

        foreach ($verifyQuery->toIterable() as $_row) {
            ++$verified;
            if (0 === $verified % $batchSize) {
                $this->entityManager->clear();
            }
        }
        $this->entityManager->clear();
        $verifyDuration = \microtime(true) - $verifyStartedAt;

        $io->table(
            ['Metric', 'Value'],
            [
                ['inserted_rows', (string) $count],
                ['streamed_rows', (string) $verified],
                ['start_memory_bytes', \number_format($startMemory)],
                ['end_memory_bytes', \number_format($endMemory)],
                ['peak_memory_bytes', \number_format($peakMemory)],
                ['peak_memory_mib', \sprintf('%.2f', $peakMemory / 1024 / 1024)],
                ['threshold_mib', '256.00'],
                ['insert_duration_seconds', \sprintf('%.2f', $insertDuration)],
                ['verify_duration_seconds', \sprintf('%.2f', $verifyDuration)],
                ['rows_per_second', \sprintf('%.1f', $count / $insertDuration)],
            ],
        );

        $output->writeln('');
        $output->writeln('# HELP pim_benchmark_peak_memory_bytes Peak memory usage during the bulk import benchmark.');
        $output->writeln('# TYPE pim_benchmark_peak_memory_bytes gauge');
        $output->writeln(\sprintf('pim_benchmark_peak_memory_bytes %d', $peakMemory));

        if (!$keep) {
            // tenant-safe: infrastructure (admin-only benchmark CLI).
            // The command runs cross-tenant by design — it cleans up
            // its own seeded rows by code prefix and is gated behind
            // direct shell access; the deleted set never includes
            // operator data because $skuPrefix is generated per-run.
            $deleted = (int) $this->entityManager->getConnection()->executeStatement(
                "DELETE FROM objects WHERE kind = 'product' AND code LIKE :prefix",
                ['prefix' => $skuPrefix.'%'],
            );
            $io->writeln(\sprintf('Cleaned up %d benchmark rows.', $deleted));
        } else {
            $io->note('--keep flag set; benchmark rows left in the database.');
        }

        if ($verified !== $count) {
            $io->warning(\sprintf(
                'Streamed %d rows but inserted %d — verification mismatch.',
                $verified,
                $count,
            ));
        }

        if ($peakMemory > self::MEMORY_THRESHOLD_BYTES) {
            $io->error(\sprintf(
                'Peak memory %.2f MiB exceeds 256 MiB threshold (R-25 violation).',
                $peakMemory / 1024 / 1024,
            ));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
