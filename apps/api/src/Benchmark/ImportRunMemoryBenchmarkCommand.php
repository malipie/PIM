<?php

declare(strict_types=1);

namespace App\Benchmark;

use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Import\Application\Handler\ImportRunHandler;
use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Enum\ImportMode;
use App\Import\Domain\Repository\ImportSessionRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Repository\TenantRepositoryInterface;
use App\Shared\Domain\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

use const STR_PAD_LEFT;

/**
 * Worker-memory benchmark for the REAL import path (#2813).
 *
 * {@see BulkImportBenchmarkCommand} has guarded the batch-handler pattern
 * since Sprint 0 and reports a flat 14 MiB at 50 000 rows — but it inserts
 * CatalogObject rows straight through the EntityManager. The path an
 * operator actually triggers, {@see ImportRunHandler}, was never measured
 * at scale, and in August 2026 it exhausted the worker's 256 MiB limit at
 * 27% of a 51 800-row file while the old benchmark stayed green. A guard
 * that measures a different code path than the one that breaks is not a
 * guard.
 *
 * What this command adds:
 *
 *   - drives `ImportRunHandler::run()` end to end (staging, chunking,
 *     value writing, undo capture) rather than a stand-in loop;
 *   - measures the **update** pass separately from the create pass. That
 *     distinction is the finding this command exists to protect: creating
 *     rows holds ~800 entities per chunk, re-importing over existing ones
 *     holds ~8 600 and starts 38 MiB higher, which is why the failure only
 *     appeared on a re-import of a full export;
 *   - reports the growth slope per 1 000 rows, not just the peak, so a
 *     regression shows up long before it reaches the ceiling.
 *
 * Cleans up everything it created unless `--keep`.
 */
#[AsCommand(
    name: 'pim:benchmark:import-run',
    description: 'Run a real import through ImportRunHandler and report the memory profile (#2813).',
)]
final class ImportRunMemoryBenchmarkCommand extends Command
{
    /** Worker ceiling this benchmark defends (`memory_limit` of the messenger process). */
    private const int LIMIT_BYTES = 256 * 1024 * 1024;

    private const string SKU_PREFIX = 'BENCH-IMP-';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantContext $tenantContext,
        private readonly ObjectTypeRepositoryInterface $objectTypes,
        private readonly ImportSessionRepositoryInterface $sessions,
        private readonly ImportRunHandler $runHandler,
        private readonly FilesystemOperator $importsStorage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('rows', 'r', InputOption::VALUE_REQUIRED, 'Rows per pass', '5000')
            ->addOption('columns', 'c', InputOption::VALUE_REQUIRED, 'Mapped attribute columns beyond sku', '6')
            ->addOption('tenant', 't', InputOption::VALUE_REQUIRED, 'Tenant code', 'demo')
            ->addOption(
                'pass',
                null,
                InputOption::VALUE_REQUIRED,
                'create | update | both — `update` re-imports the same SKUs, which is the heavy path',
                'both',
            )
            ->addOption('keep', null, InputOption::VALUE_NONE, 'Keep the rows and session after the run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rows = max(1, (int) $input->getOption('rows'));
        $columns = max(0, (int) $input->getOption('columns'));
        $pass = $input->getOption('pass');
        $keep = true === $input->getOption('keep');

        if (!\in_array($pass, ['create', 'update', 'both'], true)) {
            $io->error('--pass must be create, update or both.');

            return Command::INVALID;
        }

        $tenantOption = $input->getOption('tenant');
        $tenant = $this->tenants->findByCode($tenantOption);
        if (!$tenant instanceof Tenant) {
            $io->error(\sprintf('Tenant "%s" not found.', $input->getOption('tenant')));

            return Command::INVALID;
        }
        $this->tenantContext->set($tenant);

        $io->title(\sprintf('Import-run memory benchmark — %d rows × %d columns', $rows, $columns));

        $results = [];
        $passes = 'both' === $pass ? ['create', 'update'] : [$pass];

        $tenantCode = $tenantOption;
        foreach ($passes as $current) {
            $io->section(\sprintf('Pass: %s', $current));

            // Two-point measurement. A single run cannot separate the leak from
            // the one-off warm-up (ORM metadata, DI, first queries): dividing
            // total growth by row count charges that warm-up to every row and
            // projects a catastrophe that is not there. Running N and 2N rows
            // and taking the difference cancels the constant, leaving the
            // marginal cost per row — the number that decides whether a 50k
            // import fits.
            //
            // ImportRunHandler calls flushAndClear() internally and detaches
            // everything this command holds, so the tenant is re-resolved
            // before each run.
            $small = $this->runPass($io, $this->rebindTenant($tenantCode), $rows, $columns, 'A');
            $large = $this->runPass($io, $this->rebindTenant($tenantCode), $rows * 2, $columns, 'B');

            $marginal = ($large['after'] - $small['after']) / 1048576 / (max(1, $rows) / 1000);
            $results[$current] = [
                'peak' => max($small['peak'], $large['peak']),
                'slope' => max(0.0, $marginal),
                'seconds' => $small['seconds'] + $large['seconds'],
                'baseline' => $small['after'],
            ];
        }
        $tenant = $this->rebindTenant($tenantCode);

        if (!$keep) {
            $io->section('Cleanup');
            $io->writeln(\sprintf('Removed %d benchmark objects.', $this->cleanUp($tenant)));
        }

        $io->section('Verdict');
        $worstPeak = 0;
        foreach ($results as $name => $result) {
            $worstPeak = max($worstPeak, $result['peak']);
            $io->writeln(\sprintf(
                '%-7s peak %6.1f MiB · slope %5.2f MiB / 1000 rows · %.1f s',
                $name,
                $result['peak'] / 1048576,
                $result['slope'],
                $result['seconds'],
            ));
        }

        // Extrapolate the worst pass to the MVP catalogue size the plan
        // commits to (CLAUDE.md: 50 000 SKU). The slope matters more than
        // today's peak: a benchmark run is smaller than a real import, so a
        // pass that fits today can still fail the run it is meant to guard.
        $slopes = array_column($results, 'slope');
        $worstSlope = [] === $slopes ? 0.0 : max($slopes);
        // Project from the ALLOCATED peak, not from real usage: memory_limit
        // counts allocated bytes, and mixing the two understates the risk.
        // The peak already carries the per-chunk working set, which is what
        // grows with the number of mapped columns — the dominant term. The
        // slope then adds the marginal cost of the rows not yet run.
        $projected = $worstPeak + ($worstSlope * max(0, 50 - ($rows * 2 / 1000)) * 1048576);
        $io->writeln(\sprintf(
            'projected at 50 000 rows: %.1f MiB (ceiling %d MiB)',
            $projected / 1048576,
            self::LIMIT_BYTES / 1048576,
        ));

        if ($projected >= self::LIMIT_BYTES) {
            $io->error('Projected memory exceeds the worker ceiling — a 50k import would OOM.');

            return Command::FAILURE;
        }

        $io->success('Within the worker ceiling.');

        return Command::SUCCESS;
    }

    private function rebindTenant(string $code): Tenant
    {
        $tenant = $this->tenants->findByCode($code);
        if (!$tenant instanceof Tenant) {
            throw new RuntimeException(\sprintf('Tenant "%s" disappeared mid-run.', $code));
        }
        $this->tenantContext->set($tenant);

        return $tenant;
    }

    /**
     * @return array{peak: int, after: int, seconds: float}
     */
    private function runPass(SymfonyStyle $io, Tenant $tenant, int $rows, int $columns, string $label): array
    {
        $csv = $this->writeCsv($rows, $columns);
        $session = $this->stageSession($tenant, $csv, $rows, $columns);

        // Slope is computed from REAL usage: memory_get_usage(true) reports
        // allocator chunks rounded to 2 MiB, which is coarser than the effect
        // being measured. The ceiling comparison still uses allocated memory,
        // because that is what memory_limit counts.
        $before = memory_get_usage();
        $started = microtime(true);

        $this->runHandler->run($session);

        $seconds = microtime(true) - $started;
        $peak = memory_get_peak_usage(true);
        $after = memory_get_usage();
        @unlink($csv);

        $io->writeln(\sprintf(
            '  %s: rows %d · before %.1f MiB · after %.1f MiB · peak %.1f MiB · %.1f s',
            $label,
            $rows,
            $before / 1048576,
            $after / 1048576,
            $peak / 1048576,
            $seconds,
        ));

        return ['peak' => $peak, 'after' => $after, 'seconds' => $seconds];
    }

    private function writeCsv(int $rows, int $columns): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bench-import-').'.csv';
        $handle = fopen($path, 'w');
        if (false === $handle) {
            throw new RuntimeException('Cannot open temp CSV for writing.');
        }

        $header = ['sku'];
        for ($c = 1; $c <= $columns; ++$c) {
            $header[] = 'bench_col_'.$c;
        }
        fputcsv($handle, $header, ',', '"', '\\');

        for ($i = 1; $i <= $rows; ++$i) {
            $row = [self::SKU_PREFIX.str_pad((string) $i, 7, '0', STR_PAD_LEFT)];
            for ($c = 1; $c <= $columns; ++$c) {
                $row[] = \sprintf('value %d-%d %s', $i, $c, str_repeat('x', 24));
            }
            fputcsv($handle, $row, ',', '"', '\\');
        }
        fclose($handle);

        return $path;
    }

    private function stageSession(Tenant $tenant, string $csvPath, int $rows, int $columns): ImportSession
    {
        $productType = $this->objectTypes->findBuiltInByKind(ObjectKind::Product, $tenant);
        if (null === $productType) {
            throw new RuntimeException('Tenant has no built-in product ObjectType.');
        }
        $this->ensureAttributes($productType, $columns);

        $fileName = 'benchmark.csv';
        $session = new ImportSession(
            userId: Uuid::v7(),
            targetObjectType: $productType,
            fileName: $fileName,
            fileSizeBytes: (int) filesize($csvPath),
        );
        $session->configureRun(ImportMode::Upsert, 'sku');

        $mapping = ['sku' => 'sku'];
        for ($c = 1; $c <= $columns; ++$c) {
            $mapping['bench_col_'.$c] = 'bench_col_'.$c;
        }
        $session->setColumnMapping($mapping);
        $session->setTotalRows($rows);
        $this->sessions->save($session);

        // The handler reads its source from object storage, so the benchmark
        // stages the file exactly where a wizard upload would put it.
        $stream = fopen($csvPath, 'r');
        if (false === $stream) {
            throw new RuntimeException('Cannot open temp CSV for staging.');
        }
        $this->importsStorage->writeStream(
            \sprintf('%s/%s/%s', $tenant->getId()->toRfc4122(), $session->getId()->toRfc4122(), $fileName),
            $stream,
        );
        if (\is_resource($stream)) {
            fclose($stream);
        }

        return $session;
    }

    /**
     * Creates the benchmark's own attributes and attaches them to the product
     * type, so the run measures value writing rather than "unknown column"
     * errors. Codes are deterministic and reused across runs.
     */
    private function ensureAttributes(ObjectType $productType, int $columns): void
    {
        if (0 === $columns) {
            return;
        }

        $attached = [];
        foreach ($this->entityManager->createQuery(
            'SELECT a.code FROM '.ObjectTypeAttribute::class.' j JOIN j.attribute a WHERE j.objectType = :type',
        )->setParameter('type', $productType)->getSingleColumnResult() as $code) {
            \assert(\is_string($code));
            $attached[$code] = true;
        }

        $dirty = false;
        for ($c = 1; $c <= $columns; ++$c) {
            $code = 'bench_col_'.$c;
            if (isset($attached[$code])) {
                continue;
            }

            $existing = $this->entityManager->getRepository(Attribute::class)->findOneBy(['code' => $code]);
            $attribute = $existing instanceof Attribute
                ? $existing
                : new Attribute($code, ['en' => 'Benchmark '.$c], AttributeType::Text);
            if (!$existing instanceof Attribute) {
                $this->entityManager->persist($attribute);
            }
            $this->entityManager->persist(new ObjectTypeAttribute($productType, $attribute));
            $dirty = true;
        }

        if ($dirty) {
            $this->entityManager->flush();
        }
    }

    /**
     * Removes everything the benchmark created. Raw SQL on purpose: hydrating
     * tens of thousands of entities to delete them would blow the very budget
     * this command measures.
     *
     * tenant-safe: explicit tenant_id filter — the id list comes from a
     * SELECT scoped to the benchmark's tenant and to its own `BENCH-IMP-`
     * prefix, and both DELETEs are keyed by that list.
     */
    private function cleanUp(Tenant $tenant): int
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id::text FROM objects WHERE tenant_id = :tenant AND code LIKE :prefix',
            ['tenant' => $tenant->getId()->toRfc4122(), 'prefix' => self::SKU_PREFIX.'%'],
        );
        if ([] === $ids) {
            return 0;
        }

        $this->connection->executeStatement(
            'DELETE FROM import_undo_log WHERE object_id::text = ANY(:ids)',
            ['ids' => '{'.implode(',', $ids).'}'],
        );
        $deleted = (int) $this->connection->executeStatement(
            'DELETE FROM objects WHERE id::text = ANY(:ids)',
            ['ids' => '{'.implode(',', $ids).'}'],
        );
        $this->entityManager->clear();

        return $deleted;
    }
}
