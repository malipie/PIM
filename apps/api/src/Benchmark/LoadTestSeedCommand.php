<?php

declare(strict_types=1);

namespace App\Benchmark;

use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Provenance;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Repository\DoctrineTenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Load-test catalog seeder (GOLIVE A4, #2124).
 *
 * Seeds the MVP-scale dataset the load session in Blok B measures against:
 * N products x M schema attributes x L locales, written as CANONICAL
 * `object_values` rows (envelope `{value: ...}` per docs/api/jsonb-schemas.md).
 * The denormalised projection and the search index are deliberately NOT
 * written here — build them with the real production paths afterwards:
 *
 *   bin/console pim:catalog:detect-attributes-drift --reconcile --tenant=<code>
 *   bin/console pim:search:reindex
 *
 * That split keeps the seeder honest (no second projection composer — see
 * lessons on the drift detector) and doubles as the fresh-install rebuild
 * exercise (#2125).
 *
 * Unlike {@see BulkImportBenchmarkCommand} (identity-map memory gate), this
 * command optimises for realistic data shape: attributes are attached to the
 * built-in product ObjectType, ~1/3 are localizable (one value row per
 * locale), types rotate text/number/boolean. Batching mirrors the
 * AbstractBatchHandler contract: flush() + clear() every --batch-size
 * products, then re-find the tenant + type so listeners see managed
 * instances.
 *
 * Attribute codes (`load_attr_NNNN`) are deterministic and reused across
 * runs; product SKUs get a random per-run prefix so parallel/repeated seeds
 * never collide and `--purge` can clean up exactly what load runs created.
 */
#[AsCommand(
    name: 'pim:load:seed',
    description: 'Seed N products x M attributes x L locales with canonical object_values for the load-test session (#2124).',
)]
final class LoadTestSeedCommand extends Command
{
    private const string ATTRIBUTE_CODE_FORMAT = 'load_attr_%04d';
    private const string SKU_PREFIX_FORMAT = 'load-%s-';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DoctrineTenantRepository $tenantRepository,
        private readonly ObjectTypeRepositoryInterface $objectTypeRepository,
        private readonly TenantContext $tenantContext,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('products', 'p', InputOption::VALUE_REQUIRED, 'Number of products to insert', '50000')
            ->addOption('attributes', 'a', InputOption::VALUE_REQUIRED, 'Number of schema attributes to ensure', '200')
            ->addOption('locales', 'l', InputOption::VALUE_REQUIRED, 'Comma-separated locales for localizable values', 'pl,en,de')
            ->addOption('values-per-product', null, InputOption::VALUE_REQUIRED, 'Distinct attributes filled per product (localizable ones fan out per locale)', '24')
            ->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'Products per flush + clear cycle', '100')
            ->addOption('tenant', 't', InputOption::VALUE_REQUIRED, 'Tenant code to seed into', 'demo')
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Delete products from previous load seeds (code LIKE "load-%") instead of seeding');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $products = (int) $input->getOption('products');
        $attributeCount = (int) $input->getOption('attributes');
        $valuesPerProduct = (int) $input->getOption('values-per-product');
        $batchSize = (int) $input->getOption('batch-size');
        /** @var string $tenantCode */
        $tenantCode = $input->getOption('tenant');
        /** @var string $localesRaw */
        $localesRaw = $input->getOption('locales');
        $locales = \array_values(\array_filter(
            \array_map(trim(...), \explode(',', $localesRaw)),
            static fn (string $locale): bool => '' !== $locale,
        ));

        if ($products < 1 || $attributeCount < 1 || $batchSize < 1 || [] === $locales) {
            $io->error('--products, --attributes, --batch-size must be >= 1 and --locales non-empty.');

            return Command::INVALID;
        }
        if ($valuesPerProduct < 1 || $valuesPerProduct > $attributeCount) {
            $io->error('--values-per-product must be between 1 and --attributes.');

            return Command::INVALID;
        }

        $tenant = $this->tenantRepository->findOneBy(['code' => $tenantCode]);
        if (!$tenant instanceof Tenant) {
            $io->error(\sprintf('Tenant "%s" not found. Run `doctrine:fixtures:load` first.', $tenantCode));

            return Command::FAILURE;
        }
        $tenantId = $tenant->getId()->toRfc4122();
        $this->tenantContext->set($tenant);

        if (true === $input->getOption('purge')) {
            return $this->purge($io, $tenantId);
        }

        $productType = $this->objectTypeRepository->findBuiltInByKind(ObjectKind::Product, $tenant);
        if (!$productType instanceof ObjectType) {
            $io->error(\sprintf('Built-in product ObjectType missing for tenant "%s".', $tenantCode));

            return Command::FAILURE;
        }
        $productTypeId = $productType->getId()->toRfc4122();

        $attributeIds = $this->ensureAttributes($io, $productType, $attributeCount, $locales);

        // Re-anchor after ensureAttributes() cleared the unit of work.
        $this->rebind($tenantId);
        $productType = $this->entityManager->find(ObjectType::class, $productTypeId);
        \assert($productType instanceof ObjectType);

        $skuPrefix = \sprintf(self::SKU_PREFIX_FORMAT, \dechex(\random_int(0x100000, 0xFFFFFF)));
        $io->section(\sprintf(
            'Seeding %d products (values/product=%d, locales=%s, batch=%d) for tenant "%s", SKU prefix "%s"',
            $products,
            $valuesPerProduct,
            \implode(',', $locales),
            $batchSize,
            $tenantCode,
            $skuPrefix,
        ));

        $startedAt = \microtime(true);
        $valueRows = 0;
        $attributeTotal = \count($attributeIds);
        $progress = $io->createProgressBar($products);

        for ($i = 1; $i <= $products; ++$i) {
            $sku = \sprintf('%s%06d', $skuPrefix, $i);
            $object = new CatalogObject($productType, $sku);
            $object->forceStatus(CatalogObject::STATUS_PUBLISHED);
            $this->entityManager->persist($object);

            // Deterministic, evenly-rotating attribute window per product so
            // every attribute ends up with a comparable value population.
            $offset = ($i * 7) % $attributeTotal;
            for ($k = 0; $k < $valuesPerProduct; ++$k) {
                $attributeId = $attributeIds[($offset + $k) % $attributeTotal];
                $attribute = $this->entityManager->find(Attribute::class, $attributeId);
                \assert($attribute instanceof Attribute);

                foreach ($this->valueRowsFor($attribute, $i, $locales) as [$envelope, $locale]) {
                    $this->entityManager->persist(new ObjectValue(
                        $object,
                        $attribute,
                        $envelope,
                        Provenance::Import,
                        null,
                        $locale,
                    ));
                    ++$valueRows;
                }
            }

            if (0 === $i % $batchSize) {
                $this->entityManager->flush();
                $this->entityManager->clear();
                $this->rebind($tenantId);
                $productType = $this->entityManager->find(ObjectType::class, $productTypeId);
                \assert($productType instanceof ObjectType);
                $progress->advance($batchSize);
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
        $progress->finish();
        $io->newLine(2);

        $duration = \microtime(true) - $startedAt;
        $peak = \memory_get_peak_usage(true);
        $io->table(
            ['Metric', 'Value'],
            [
                ['products', (string) $products],
                ['schema_attributes', (string) $attributeTotal],
                ['object_value_rows', (string) $valueRows],
                ['duration_seconds', \sprintf('%.1f', $duration)],
                ['products_per_second', \sprintf('%.1f', $products / \max($duration, 0.001))],
                ['peak_memory_mib', \sprintf('%.1f', $peak / 1024 / 1024)],
                ['sku_prefix', $skuPrefix],
            ],
        );

        $io->note([
            'Canonical values only — build the projections with the real paths:',
            \sprintf('bin/console pim:catalog:detect-attributes-drift --reconcile --tenant=%s', $tenantCode),
            'bin/console pim:search:reindex',
        ]);

        return Command::SUCCESS;
    }

    /**
     * Ensures `load_attr_NNNN` attributes exist and are attached to the
     * product ObjectType. Deterministic codes make re-runs idempotent; the
     * type/localizable mix rotates so 1 in 3 attributes fans out per locale.
     *
     * @param list<string> $locales
     *
     * @return list<string> attribute ids (RFC 4122), ordered by code
     */
    private function ensureAttributes(SymfonyStyle $io, ObjectType $productType, int $count, array $locales): array
    {
        $existing = $this->entityManager->createQuery(
            'SELECT a.code AS code, a.id AS id FROM '.Attribute::class." a WHERE a.code LIKE 'load_attr_%'",
        )->getArrayResult();
        /** @var array<string, string> $byCode */
        $byCode = [];
        foreach ($existing as $row) {
            \assert(\is_array($row) && \is_string($row['code']));
            $id = $row['id'];
            \assert($id instanceof Uuid || \is_string($id));
            $byCode[$row['code']] = $id instanceof Uuid ? $id->toRfc4122() : $id;
        }

        $attached = $this->entityManager->createQuery(
            'SELECT a.code FROM '.ObjectTypeAttribute::class.' j JOIN j.attribute a WHERE j.objectType = :type',
        )->setParameter('type', $productType)->getSingleColumnResult();
        $attachedCodes = [];
        foreach ($attached as $attachedCode) {
            \assert(\is_string($attachedCode));
            $attachedCodes[$attachedCode] = true;
        }

        $created = 0;
        $ids = [];
        for ($n = 1; $n <= $count; ++$n) {
            $code = \sprintf(self::ATTRIBUTE_CODE_FORMAT, $n);
            if (isset($byCode[$code])) {
                if (!isset($attachedCodes[$code])) {
                    $reused = $this->entityManager->find(Attribute::class, $byCode[$code]);
                    \assert($reused instanceof Attribute);
                    $this->entityManager->persist(new ObjectTypeAttribute($productType, $reused, false, 1000 + $n));
                }
                $ids[] = $byCode[$code];
                continue;
            }

            $type = match ($n % 5) {
                0 => AttributeType::Number,
                4 => AttributeType::Boolean,
                default => AttributeType::Text,
            };
            $attribute = new Attribute($code, ['pl' => 'Atrybut load '.$n, 'en' => 'Load attribute '.$n], $type);
            if (0 === $n % 3 && AttributeType::Text === $type) {
                $attribute->changeLocalizable(true);
            }
            $this->entityManager->persist($attribute);
            if (!isset($attachedCodes[$code])) {
                $this->entityManager->persist(new ObjectTypeAttribute($productType, $attribute, false, 1000 + $n));
            }
            $ids[] = $attribute->getId()->toRfc4122();
            ++$created;
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $io->writeln(\sprintf('Schema: %d load attributes ensured (%d created, %d reused), locales: %s.', $count, $created, $count - $created, \implode(',', $locales)));

        return $ids;
    }

    /**
     * @param list<string> $locales
     *
     * @return list<array{0: array<string, mixed>, 1: ?string}> envelope + locale pairs
     */
    private function valueRowsFor(Attribute $attribute, int $productIndex, array $locales): array
    {
        $code = $attribute->getCode();
        $scalar = match ($attribute->getType()) {
            AttributeType::Number => ($productIndex % 997) + 0.5,
            AttributeType::Boolean => 0 === $productIndex % 2,
            default => \sprintf('Value %s #%06d', $code, $productIndex),
        };

        if (!$attribute->isLocalizable()) {
            return [[['value' => $scalar], null]];
        }

        $rows = [];
        foreach ($locales as $locale) {
            $rows[] = [['value' => \sprintf('%s [%s]', \is_string($scalar) ? $scalar : (string) $productIndex, $locale)], $locale];
        }

        return $rows;
    }

    private function rebind(string $tenantId): void
    {
        $tenant = $this->tenantRepository->find($tenantId);
        \assert($tenant instanceof Tenant);
        $this->tenantContext->set($tenant);
    }

    private function purge(SymfonyStyle $io, string $tenantId): int
    {
        // tenant-safe: infrastructure (admin-only load-test CLI). Scoped to
        // the resolved tenant id AND the load-test SKU prefix; object_values
        // cascade via the object_values_object_fk ON DELETE CASCADE.
        $deleted = (int) $this->entityManager->getConnection()->executeStatement(
            "DELETE FROM objects WHERE kind = 'product' AND code LIKE 'load-%' AND tenant_id = :tenant",
            ['tenant' => $tenantId],
        );
        $io->success(\sprintf('Purged %d load-test products (canonical values cascade).', $deleted));
        $io->note('Schema attributes load_attr_* are kept for reuse. Run pim:search:reindex to drop their documents.');

        return Command::SUCCESS;
    }
}
