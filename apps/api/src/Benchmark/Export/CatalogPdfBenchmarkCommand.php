<?php

declare(strict_types=1);

namespace App\Benchmark\Export;

use App\Asset\Contracts\Service\AssetInliner;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Export\Catalog\Application\CatalogRenderService;
use App\Export\Catalog\Application\HtmlValueSanitizer;
use App\Export\Catalog\Domain\Entity\CatalogProfile;
use App\Export\Catalog\Domain\Enum\CatalogTemplateKind;
use App\Export\Catalog\Domain\Mapping\CatalogItemMapper;
use App\Export\Catalog\Domain\Template\CatalogTemplateCatalog;
use App\Export\Catalog\Infrastructure\Renderer\DompdfRenderer;
use App\Export\Contracts\CatalogProductScope;
use App\Export\Contracts\CatalogProductValues;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use App\Shared\Infrastructure\Doctrine\RlsTenantGuard;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

use const PHP_INT_MAX;

/**
 * CPDF-P6-04 (#2307) — Dompdf catalog render benchmark, the CPDF sibling of
 * {@see ExportBenchmarkCommand} (EXP-04).
 *
 * Resolves open decision A: Dompdf builds the whole PDF document in PHP
 * memory, so this command renders the chosen archetype at increasing product
 * counts (default 50/100/150/300/500) through the REAL pipeline
 * (CatalogProductValues → CatalogItemMapper → Twig → DompdfRenderer) and
 * reports elapsed time + PHP peak memory per size. The numbers pick the
 * CATALOG_PDF_MAX_ITEMS default enforced by {@see CatalogRenderService}.
 *
 * The value source wraps the real adapter and CYCLES the tenant's products
 * until the requested count — the dev dataset (~300 demo products) can then
 * exercise the 500-product point; render cost depends on document size, not
 * on row distinctness. Sizes run ascending so `memory_get_peak_usage()`
 * (monotonic per process) stays attributable to the size that moved it.
 *
 * Output: stdout table + an append-only snapshot in
 * `agent/cpdf-p6-04-dompdf-benchmark.md` (historical trend over runs).
 */
#[AsCommand(
    name: 'pim:catalog:benchmark',
    description: 'Render a catalog archetype at increasing product counts on Dompdf and report time + peak memory (CPDF-P6-04).'
)]
final class CatalogPdfBenchmarkCommand extends Command
{
    private const string REPORT_PATH = 'agent/cpdf-p6-04-dompdf-benchmark.md';

    public function __construct(
        private readonly CatalogProductValues $values,
        private readonly CatalogTemplateCatalog $templates,
        private readonly ObjectTypeRepositoryInterface $objectTypes,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly TenantFilterConfigurator $tenantFilter,
        private readonly RlsTenantGuard $rlsGuard,
        private readonly Environment $twig,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant code to scope the benchmark', 'demo')
            ->addOption('template', null, InputOption::VALUE_REQUIRED, 'Archetype to render (sheet|pricelist|grid)', 'grid')
            ->addOption('sizes', null, InputOption::VALUE_REQUIRED, 'Comma-separated ascending product counts', '50,100,150,300,500')
            ->addOption('no-report', null, InputOption::VALUE_NONE, 'Skip appending to '.self::REPORT_PATH);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tenantCode = $this->stringOption($input, 'tenant');
        $kind = CatalogTemplateKind::tryFrom($this->stringOption($input, 'template'));
        if (null === $kind) {
            $io->error('template must be one of: sheet, pricelist, grid.');

            return Command::FAILURE;
        }
        $sizes = array_values(array_filter(array_map(
            static fn (string $s): int => (int) trim($s),
            explode(',', $this->stringOption($input, 'sizes')),
        ), static fn (int $n): bool => $n > 0));
        sort($sizes);
        if ([] === $sizes) {
            $io->error('sizes must contain at least one positive count.');

            return Command::FAILURE;
        }

        $tenant = $this->entityManager->getRepository(Tenant::class)->findOneBy(['code' => $tenantCode]);
        if (!$tenant instanceof Tenant) {
            $io->error(sprintf('Tenant "%s" not found.', $tenantCode));

            return Command::FAILURE;
        }
        $this->tenantContext->set($tenant);
        $this->tenantFilter->apply();
        // CLI has no request listener — establish the RLS GUC explicitly, or
        // pim_app's row-level policies return zero rows on every domain table.
        $this->rlsGuard->reassert($tenant);

        $productType = $this->objectTypes->findBuiltInByKind(ObjectKind::Product, $tenant);
        if (null === $productType) {
            $io->error('No built-in product ObjectType for this tenant — seed the demo dataset first.');

            return Command::FAILURE;
        }

        $template = $this->templates->get($kind);
        $profile = new CatalogProfile(
            'benchmark',
            'Benchmark catalog',
            $kind,
            $productType->getId(),
            branding: ['color' => '#1d4ed8', 'company_name' => 'Benchmark'],
            fieldMappings: array_map(
                static fn (array $mapping): array => [
                    'slot' => $mapping['slot'],
                    'source' => ['kind' => 'attribute', 'ref' => $mapping['source']],
                ],
                $template->defaultMappings,
            ),
        );

        $io->section(sprintf(
            'Dompdf catalog benchmark: tenant=%s, template=%s, sizes=%s',
            $tenantCode,
            $kind->value,
            implode(',', $sizes),
        ));

        $rows = [];
        foreach ($sizes as $count) {
            $service = new CatalogRenderService(
                $this->cyclingValues($count),
                new CatalogItemMapper(),
                $this->templates,
                new HtmlValueSanitizer(),
                $this->twig,
                new DompdfRenderer(),
                // Synthetic products carry no real assets — a no-op inliner keeps
                // the benchmark measuring the render path only.
                new class implements AssetInliner {
                    public function toDataUri(string $reference): ?string
                    {
                        return null;
                    }
                },
                maxInMemoryItems: PHP_INT_MAX, // the benchmark measures past the cap on purpose
            );

            $target = tempnam(sys_get_temp_dir(), 'cpdf-bench-');
            if (false === $target) {
                $io->error('Unable to allocate a temp file.');

                return Command::FAILURE;
            }

            gc_collect_cycles();
            $startedAt = microtime(true);
            $result = $service->render($profile, $target);
            $elapsedMs = (microtime(true) - $startedAt) * 1000.0;
            $peakMb = memory_get_peak_usage(true) / 1024 / 1024;
            @unlink($target);

            $rows[] = [
                'products' => $result->itemCount,
                'pages' => $result->pageCount,
                'elapsed_ms' => $elapsedMs,
                'peak_mb' => $peakMb,
                'pdf_kb' => $result->byteSize / 1024,
            ];
            $io->writeln(sprintf(
                '  %5d products → %3d pages, %8.1f ms, peak %7.1f MB, %7.1f KB pdf',
                $result->itemCount,
                $result->pageCount,
                $elapsedMs,
                $peakMb,
                $result->byteSize / 1024,
            ));
        }

        if (!$input->getOption('no-report')) {
            $this->appendReport($tenantCode, $kind->value, $rows);
            $io->success(sprintf('Snapshot appended to %s', self::REPORT_PATH));
        }

        return Command::SUCCESS;
    }

    /**
     * Wrap the real value source: cycle its rows until $target products so the
     * benchmark can exceed the dev dataset size.
     */
    private function cyclingValues(int $target): CatalogProductValues
    {
        $inner = $this->values;

        return new class($inner, $target) implements CatalogProductValues {
            public function __construct(
                private readonly CatalogProductValues $inner,
                private readonly int $target,
            ) {
            }

            public function forScope(CatalogProductScope $scope): iterable
            {
                $emitted = 0;
                while ($emitted < $this->target) {
                    $before = $emitted;
                    foreach ($this->inner->forScope($scope) as $row) {
                        yield $row;
                        if (++$emitted >= $this->target) {
                            return;
                        }
                    }
                    if ($emitted === $before) {
                        return; // empty source — avoid an infinite loop
                    }
                }
            }
        };
    }

    private function stringOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return \is_string($value) ? $value : '';
    }

    /**
     * @param list<array{products: int, pages: int, elapsed_ms: float, peak_mb: float, pdf_kb: float}> $rows
     */
    private function appendReport(string $tenantCode, string $template, array $rows): void
    {
        $path = $this->projectDir.'/'.self::REPORT_PATH;
        if (!is_dir(\dirname($path))) {
            @mkdir(\dirname($path), 0o755, true);
        }

        $timestamp = new DateTimeImmutable()->format(DateTimeInterface::ATOM);
        $lines = '';
        foreach ($rows as $row) {
            $lines .= sprintf(
                "| %s | %s | %s | %d | %d | %.1f | %.1f | %.1f |\n",
                $timestamp,
                $tenantCode,
                $template,
                $row['products'],
                $row['pages'],
                $row['elapsed_ms'],
                $row['peak_mb'],
                $row['pdf_kb'],
            );
        }

        $needsHeader = !file_exists($path);
        $fh = fopen($path, 'a');
        if (false === $fh) {
            return;
        }
        try {
            if ($needsHeader) {
                fwrite($fh, $this->reportHeader());
            }
            fwrite($fh, $lines);
        } finally {
            fclose($fh);
        }
    }

    private function reportHeader(): string
    {
        return <<<MD
            # CPDF-P6-04 — Dompdf catalog render benchmark log

            > **Ticket:** [#2307](https://github.com/malipie/PIM/issues/2307)
            >
            > Append-only run log produced by `bin/console pim:catalog:benchmark`.
            > Each row renders the archetype at the given product count through the
            > real pipeline (values → mapper → Twig → Dompdf) inside one process,
            > sizes ascending. `peak_mb` is `memory_get_peak_usage(true)` — the
            > worker guardrail number (CLAUDE.md §3.10 alert fires at 256 MB).
            > Decision A: the numbers picked the `CATALOG_PDF_MAX_ITEMS` default
            > (see `apps/api/.env`); exceeding it on Dompdf raises
            > `CatalogTooLargeException` ("enable Gotenberg") instead of an OOM.

            | timestamp | tenant | template | products | pages | elapsed_ms | peak_mb | pdf_kb |
            |---|---|---|---|---|---|---|---|

            MD;
    }
}
