<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Command;

use App\Catalog\Application\AttributesIndexedRebuilder;
use App\Catalog\Application\BulkContext;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectValueRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Repository\TenantRepositoryInterface;
use App\Shared\Domain\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * AUD-039 / G-01 — detect (and optionally reconcile) drift between the
 * denormalised `objects.attributes_indexed` cache and the canonical
 * `object_values` rows.
 *
 * The cache holds only the GLOBAL reading (locale=null, channel=null) keyed
 * by `Attribute.code`, copied verbatim from `ObjectValue.value`
 * ({@see AttributesIndexedRebuilder}). Drift is therefore measured against
 * the same global slice the rebuilder writes:
 *   - ORPHANED   — a code in the cache with no global ObjectValue row.
 *   - MISSING    — a global ObjectValue row whose code is absent from the cache.
 *   - MISMATCHED — a code in both whose JSONB value differs.
 *
 * `RebuildAttributesIndexedHandler` used to silently skip an object after
 * exhausting its version-conflict retries, and the only reconciliation was the
 * completeness backfill (which never reports drift). This command closes that
 * gap: it is exit-code aware (non-zero on drift, in detect mode) so CI / cron
 * fails loudly, and `--reconcile` reuses {@see AttributesIndexedRebuilder} to
 * rewrite the cache from the canon — never touching `object_values`.
 *
 * Tenant scoping: FORCE RLS hides every `objects` / `object_values` row from
 * the application role until the `app.current_tenant` GUC is set, so the scan
 * runs per tenant — it binds {@see TenantContext} (TenantFilter) AND the
 * Postgres GUC (RLS), exactly like {@see \App\Shared\Infrastructure\Messenger\TenantRlsGucMiddleware}
 * does for workers. Omit `--tenant` to sweep every tenant (cron-friendly).
 *
 * Memory shape (#2231): keyset pagination + `EntityManager::clear()` every
 * {@see self::FLUSH_EVERY} objects, with each page's values batch-loaded in one
 * query. The earlier `toIterable()` walk opened no server-side cursor, so libpq
 * buffered the whole result set client-side — memory `clear()` cannot reclaim —
 * and the per-object value lookup turned a 50k reconcile into ~1.7M queries. It
 * ran for 110 minutes without finishing, at 213 MiB against a 256 MiB ceiling.
 */
#[AsCommand(
    name: 'pim:catalog:detect-attributes-drift',
    description: 'Detect (and with --reconcile fix) drift between attributes_indexed cache and canonical object_values.',
)]
final class DetectAttributesDriftCommand extends Command
{
    private const int FLUSH_EVERY = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly AttributesIndexedRebuilder $rebuilder,
        private readonly BulkContext $bulkContext,
        private readonly TenantContext $tenantContext,
        private readonly TenantRepositoryInterface $tenants,
        private readonly ObjectValueRepositoryInterface $objectValues,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'tenant',
                null,
                InputOption::VALUE_REQUIRED,
                'Tenant code to scope the scan. Omit to scan every tenant.',
            )
            ->addOption(
                'kind',
                null,
                InputOption::VALUE_REQUIRED,
                'ObjectKind code (`product`, `category`, `asset`, `brand`) or `all`.',
                'all',
            )
            ->addOption(
                'reconcile',
                null,
                InputOption::VALUE_NONE,
                'Rewrite attributes_indexed from object_values for every drifted object. Only the cache is touched.',
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Max number of drifted objects to list per kind in the report (the count is always exact).',
                '50',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string|null $tenantCode */
        $tenantCode = $input->getOption('tenant');
        if (null !== $tenantCode && '' === $tenantCode) {
            $io->error('--tenant must be a non-empty string when provided.');

            return Command::INVALID;
        }

        /** @var string $kindOption */
        $kindOption = $input->getOption('kind');
        $kinds = 'all' === $kindOption
            ? [ObjectKind::Product, ObjectKind::Category, ObjectKind::Asset]
            : [ObjectKind::from($kindOption)];

        /** @var bool $reconcile */
        $reconcile = $input->getOption('reconcile');
        /** @var string $limitOption */
        $limitOption = $input->getOption('limit');
        $listLimit = max(0, (int) $limitOption);

        $tenants = $this->resolveTenants($tenantCode);
        if (null === $tenants) {
            $io->error(\sprintf('Tenant "%s" not found.', (string) $tenantCode));

            return Command::FAILURE;
        }

        // Bulk path: silence the synchronous AttributesIndexedSyncListener so a
        // --reconcile flush does not recurse into a per-object rebuild.
        $this->bulkContext->setBulk(true);

        $totalScanned = 0;
        $totalDrifted = 0;
        $totalReconciled = 0;

        try {
            foreach ($tenants as $tenant) {
                $this->bindTenant($tenant);
                try {
                    foreach ($kinds as $kind) {
                        $io->section(\sprintf('tenant=%s, kind=%s', $tenant->getCode(), $kind->value));

                        $report = $this->scanKind($kind, $reconcile, $listLimit);

                        $totalScanned += $report['scanned'];
                        $totalDrifted += $report['drifted'];
                        $totalReconciled += $report['reconciled'];

                        $io->writeln(\sprintf(
                            '  scanned=%d, drifted=%d%s',
                            $report['scanned'],
                            $report['drifted'],
                            $reconcile ? \sprintf(', reconciled=%d', $report['reconciled']) : '',
                        ));

                        foreach ($report['samples'] as $line) {
                            $io->writeln('    '.$line);
                        }
                        if ($report['drifted'] > \count($report['samples'])) {
                            $io->writeln(\sprintf(
                                '    … and %d more (raise --limit to list).',
                                $report['drifted'] - \count($report['samples']),
                            ));
                        }
                    }
                } finally {
                    $this->unbindTenant();
                }
            }
        } finally {
            $this->bulkContext->setBulk(false);
        }

        if (0 === $totalDrifted) {
            $io->success(\sprintf('No drift. %d object(s) scanned.', $totalScanned));

            return Command::SUCCESS;
        }

        if ($reconcile) {
            // After a reconcile the cache is back in sync; report success so a
            // cron `--reconcile` run does not page on self-healed drift.
            $io->success(\sprintf(
                '%d drifted object(s) of %d scanned reconciled — cache rewritten from object_values.',
                $totalReconciled,
                $totalScanned,
            ));

            return Command::SUCCESS;
        }

        $io->warning(\sprintf(
            '%d drifted object(s) of %d scanned. Re-run with --reconcile to rewrite the cache.',
            $totalDrifted,
            $totalScanned,
        ));

        return Command::FAILURE;
    }

    /**
     * @return list<Tenant>|null null when an explicit --tenant code does not resolve
     */
    private function resolveTenants(?string $tenantCode): ?array
    {
        if (null !== $tenantCode) {
            $tenant = $this->tenants->findByCode($tenantCode);

            return $tenant instanceof Tenant ? [$tenant] : null;
        }

        return $this->tenants->findAllOrderedByCode();
    }

    /**
     * Bind the tenant on BOTH isolation layers so the scan sees the tenant's
     * rows: the PHP-side {@see TenantContext} (TenantFilter) and the Postgres
     * `app.current_tenant` GUC (FORCE RLS). Mirrors TenantRlsGucMiddleware.
     */
    private function bindTenant(Tenant $tenant): void
    {
        $this->tenantContext->set($tenant);
        // tenant-safe: infrastructure (establishes the tenant_id RLS policies read in this CLI session; this IS the tenant boundary, not a bypass)
        $this->connection->executeStatement(
            "SELECT set_config('app.current_tenant', :tenant_id, false)",
            ['tenant_id' => $tenant->getId()->toRfc4122()],
        );
        // tenant-safe: infrastructure (the maintenance CLI never runs as super-admin)
        $this->connection->executeStatement("SELECT set_config('app.is_super_admin', 'false', false)");
    }

    private function unbindTenant(): void
    {
        $this->tenantContext->clear();
        // tenant-safe: infrastructure (resets the RLS tenant marker so the next tenant in the sweep starts clean)
        $this->connection->executeStatement("SELECT set_config('app.current_tenant', '', false)");
    }

    /**
     * @return array{scanned: int, drifted: int, reconciled: int, samples: list<string>}
     */
    private function scanKind(ObjectKind $kind, bool $reconcile, int $listLimit): array
    {
        $scanned = 0;
        $drifted = 0;
        $reconciled = 0;
        $samples = [];

        // #2231 — keyset pagination instead of toIterable(). Doctrine's
        // toIterable() does not open a server-side cursor: libpq buffers the
        // ENTIRE result set client-side before hydration starts, so a 50k
        // catalogue paid tens of MB of driver buffer that EntityManager::clear()
        // cannot reclaim. Walking by id keeps both the buffer and the identity
        // map bounded by page size.
        $cursor = null;
        while (true) {
            $page = $this->loadPage($kind, $cursor);
            if ([] === $page) {
                break;
            }
            $cursor = $page[array_key_last($page)]->getId()->toRfc4122();

            // One query for the page's values instead of one per object. The
            // per-object lookup below used to issue ~13 queries per row, which
            // is what turned a 50k reconcile into a multi-hour run.
            $valuesByObject = $this->valuesForPage($page);

            foreach ($page as $object) {
                ++$scanned;

                $values = $valuesByObject[$object->getId()->toRfc4122()] ?? [];
                $diff = $this->diffObject($object, $values);
                if ([] !== $diff['orphaned'] || [] !== $diff['missing'] || [] !== $diff['mismatched']) {
                    ++$drifted;
                    if (\count($samples) < $listLimit) {
                        $samples[] = $this->formatDiff($object, $diff);
                    }
                    if ($reconcile) {
                        // Reuse the canonical rebuilder — the SINGLE writer of the
                        // cache (jsonb-schemas contract). Reads object_values, never
                        // mutates them; only attributes_indexed + completeness change.
                        //
                        // #2231 — hand it the page's values. Left to fetch its own
                        // it re-read them per drifted object, which is the per-row
                        // round trip this ticket removed from the scan re-appearing
                        // in the repair.
                        $this->rebuilder->rebuild($object, $values);
                        ++$reconciled;
                    }
                }
            }

            if ($reconcile) {
                $this->em->flush();
            }
            $this->em->clear();
        }

        if ($reconcile) {
            $this->em->flush();
        }
        $this->em->clear();

        return [
            'scanned' => $scanned,
            'drifted' => $drifted,
            'reconciled' => $reconciled,
            'samples' => $samples,
        ];
    }

    /**
     * One page of objects of the given kind, ordered by id (#2231).
     *
     * @return list<CatalogObject>
     */
    private function loadPage(ObjectKind $kind, ?string $afterId): array
    {
        $dql = 'SELECT o FROM '.CatalogObject::class.' o WHERE o.kind = :kind';
        if (null !== $afterId) {
            $dql .= ' AND o.id > :cursor';
        }
        $query = $this->em->createQuery($dql.' ORDER BY o.id ASC')
            ->setParameter('kind', $kind->value)
            ->setMaxResults(self::FLUSH_EVERY);
        if (null !== $afterId) {
            $query->setParameter('cursor', $afterId);
        }

        /** @var list<CatalogObject> $result */
        $result = $query->getResult();

        return $result;
    }

    /**
     * Values of a whole page in one query, keyed by object id (#2231).
     *
     * @param list<CatalogObject> $page
     *
     * @return array<string, list<ObjectValue>>
     */
    private function valuesForPage(array $page): array
    {
        $ids = array_map(static fn (CatalogObject $o): Uuid => $o->getId(), $page);

        /** @var array<string, list<ObjectValue>> $byObject */
        $byObject = $this->objectValues->findByObjectIds($ids);

        return $byObject;
    }

    /**
     * @param list<ObjectValue> $values pre-loaded values of this object (#2231)
     *
     * @return array{orphaned: list<string>, missing: list<string>, mismatched: list<string>}
     */
    private function diffObject(CatalogObject $object, array $values): array
    {
        $canon = $this->canonicalGlobalReading($values);
        $cache = $object->getAttributesIndexed();

        // Both maps are keyed by Attribute.code (a non-numeric identifier such
        // as `sku` / `brand`), so keys stay string on both sides and direct
        // array_key_exists comparisons are symmetric.
        $orphaned = [];
        $mismatched = [];
        foreach ($cache as $code => $cachedValue) {
            if (!\array_key_exists($code, $canon)) {
                $orphaned[] = $code;
                continue;
            }
            if ($this->normalised($canon[$code]) !== $this->normalised($cachedValue)) {
                $mismatched[] = $code;
            }
        }

        $missing = [];
        foreach ($canon as $code => $canonValue) {
            if (!\array_key_exists($code, $cache)) {
                $missing[] = $code;
            }
        }

        sort($orphaned);
        sort($missing);
        sort($mismatched);

        return ['orphaned' => $orphaned, 'missing' => $missing, 'mismatched' => $mismatched];
    }

    /**
     * Recursively sorts associative keys so the comparison ignores key order
     * (list 0..n order is preserved — element order in multiselects is
     * meaningful).
     *
     * GOLIVE #2186 — Postgres jsonb canonicalises object keys on write
     * (length, then bytes), while globalSlot() appends `provenance` after the
     * value envelope. For envelopes whose key sorts AFTER "provenance"
     * (`option_code` = 11 chars vs 10) the two orders diverge, and the strict
     * `!==` reported an eternal false mismatch: select/multiselect values
     * never converged no matter how many times --reconcile rewrote the cache
     * (the rewrite itself round-trips through jsonb and reorders again).
     * Text-style `{value}` envelopes sorted BEFORE "provenance" by accident,
     * which is why only select-type attributes were affected.
     */
    private function normalised(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = $this->normalised($item);
        }
        if (!array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }

    /**
     * The GLOBAL slice of object_values, keyed by attribute code — the exact
     * shape {@see AttributesIndexedRebuilder::rebuild()} writes into the cache.
     *
     * @param list<ObjectValue> $values pre-loaded values (#2231 — the caller
     *                                  batch-loads a page instead of issuing
     *                                  one query per object)
     *
     * @return array<string, mixed>
     */
    private function canonicalGlobalReading(array $values): array
    {
        $canon = [];
        foreach ($values as $value) {
            if (null !== $value->getLocale() || null !== $value->getChannelId()) {
                continue;
            }
            // AGENT-P6-05 (#1978) — the slot carries provenance now; derive
            // it through the rebuilder so both sides compose identically.
            $canon[$value->getAttribute()->getCode()] = $this->rebuilder->globalSlot($value);
        }

        return $canon;
    }

    /**
     * @param array{orphaned: list<string>, missing: list<string>, mismatched: list<string>} $diff
     */
    private function formatDiff(CatalogObject $object, array $diff): string
    {
        $parts = [];
        if ([] !== $diff['orphaned']) {
            $parts[] = 'orphaned='.implode(',', $diff['orphaned']);
        }
        if ([] !== $diff['missing']) {
            $parts[] = 'missing='.implode(',', $diff['missing']);
        }
        if ([] !== $diff['mismatched']) {
            $parts[] = 'mismatched='.implode(',', $diff['mismatched']);
        }

        return \sprintf('%s [%s]', $object->getCode(), implode(' ', $parts));
    }
}
