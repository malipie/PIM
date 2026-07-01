<?php

declare(strict_types=1);

namespace App\Export\Application\Sync;

use App\Catalog\Application\Filter\FilterDslResolver;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Export\Application\Builder\ColumnResolver;
use App\Export\Application\Builder\ExportBuilder;
use App\Export\Application\Builder\PublicationColumnPlanner;
use App\Export\Application\Builder\Structural\StructuralExportBuilderInterface;
use App\Export\Domain\Entity\ExportSession;
use App\Export\Domain\Enum\ExportEncoding;
use App\Export\Domain\Enum\ExportEntityType;
use App\Export\Domain\Enum\ExportFormat;
use App\Export\Domain\Enum\ExportTargetScope;
use App\Export\Domain\Repository\ExportSessionRepositoryInterface;
use App\Export\Infrastructure\Writer\CsvStreamWriter;
use App\Export\Infrastructure\Writer\GenericXmlWriter;
use App\Export\Infrastructure\Writer\PositionalRowSink;
use App\Export\Infrastructure\Writer\RowSink;
use App\Export\Infrastructure\Writer\RowWriter;
use App\Export\Infrastructure\Writer\XlsxStreamWriter;
use App\Export\Infrastructure\Writer\XmlRowSink;
use App\Export\Infrastructure\Writer\XmlWriterCore;
use App\Shared\Domain\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Runs the catalog-object + structural export paths end-to-end.
 *
 * AUD-015 (#1632): catalog-object exports resolve an ordered emit-id PLAN per
 * {@see ExportTargetScope} ({@see buildEmitIdPlan()}, id-only — no entity
 * hydration) and stream it through {@see ExportBuilder} in CLEAR_INTERVAL-sized
 * keyset pages, clearing the EntityManager between pages so a 50k export stays
 * in constant memory for EVERY scope (Selected / Filter / All) and both
 * include_variants states — not just the old All-masters fast path. Wraps the
 * file write in a try/finally so the temp file is cleaned up on partial failure.
 *
 * Filter scope (EXP-20 #632) compiles the session's `filter_snapshot`
 * via {@see FilterDslResolver::toCountSql()} into a tenant-scoped
 * SQL WHERE clause and fetches the matching `objects` IDs — mirrors the
 * SmartFilterPresetController::resolveCounts pattern so we get the
 * same operator coverage (PRD §5.5) without re-implementing the DSL.
 *
 * Sync writes ALWAYS complete in a single request — no Mercure, no
 * status transitions beyond `done` or `error`. The async handler
 * (EXP-06) takes over from 100 rows up; both reuse this runner so the
 * streaming + memory contract is identical.
 */
final class SyncExportRunner
{
    public const int PROGRESS_CHUNK = 500;

    /** IMP2-2.6 / AUD-015 — hydrate + detach this many objects per keyset page so streaming stays flat. */
    private const int CLEAR_INTERVAL = 200;

    public function __construct(
        private readonly ExportBuilder $builder,
        private readonly CatalogObjectRepositoryInterface $objects,
        private readonly ExportSessionRepositoryInterface $sessions,
        private readonly FilterDslResolver $filterDsl,
        private readonly Connection $connection,
        private readonly PublicationColumnPlanner $columnPlanner,
        private readonly ColumnResolver $columnResolver,
        private readonly ObjectTypeRepositoryInterface $objectTypes,
        private readonly EntityManagerInterface $em,
        /** @var iterable<StructuralExportBuilderInterface> */
        #[AutowireIterator('app.export.structural_builder')]
        private readonly iterable $structuralBuilders = [],
    ) {
    }

    /**
     * Count the rows an export will produce, used by the controller to route
     * sync vs async. Structural types count via their builder; catalog-object
     * types count the resolved id PLAN — never hydrating the object graph
     * (AUD-015 #1632: the pre-1632 path materialised the whole target set just
     * to size it, an OOM vector mirroring the run path).
     */
    public function resolveTargetCount(ExportSession $session): int
    {
        if ($session->getEntityType()->isStructural()) {
            return $this->structuralBuilderFor($session->getEntityType())->count($this->requireTenant($session));
        }

        $tenant = $this->requireTenant($session);
        $objectType = $this->resolveObjectType($session, $tenant);

        return \count($this->buildEmitIdPlan($session, $tenant, $objectType));
    }

    /**
     * AUD-015 (#1632) — the ordered list of object ids a catalog-object export
     * will emit, resolved WITHOUT hydrating any entity. This is the single
     * source of truth for both the row count (sync/async routing) and the
     * streaming run: {@see runCatalogObjectToFile()} pages the hydration over
     * this list (CLEAR_INTERVAL ids at a time, EntityManager::clear() between
     * pages) so the full object graph never lives in memory at once — for ALL
     * scopes (Selected / Filter / All) with or without include_variants, not
     * just the old All-masters fast path.
     *
     * Order contract (preserves the pre-1632 applyVariantFanout semantics the
     * variants golden relies on):
     *   - base ids ascending (UUID v7 → chronological, deterministic);
     *   - masters-only: base ids minus any non-root;
     *   - include_variants: each base id, then (if it is a root) its child ids
     *     ordered by code; a directly-selected orphan variant (master absent)
     *     is still emitted; every id appears once (cross-page dedup via a
     *     string set — RFC4122 strings survive clear() and stay bounded,
     *     ~80 B/id, the same touched-id pattern the import uses).
     *
     * @return list<string>
     */
    private function buildEmitIdPlan(ExportSession $session, Tenant $tenant, ObjectType $objectType): array
    {
        $baseIds = $this->resolveBaseIds($session, $tenant, $objectType);
        if ([] === $baseIds) {
            return [];
        }

        // The roots among the base set drive variant fan-out. For scope All the
        // base IS the root id set already; Selected/Filter need the partition.
        $rootIds = ExportTargetScope::All === $session->getTargetScope()
            ? $baseIds
            : $this->objects->filterRootObjectIds($baseIds, $tenant);

        if (!$session->includesVariants()) {
            // Masters only — emit the roots in base order.
            $rootSet = array_fill_keys($rootIds, true);

            return array_values(array_filter($baseIds, static fn (string $id): bool => isset($rootSet[$id])));
        }

        $childIdsByParent = $this->objects->findChildIdsByParentIds($rootIds, $tenant);

        $plan = [];
        $seen = [];
        foreach ($baseIds as $id) {
            if (isset($seen[$id])) {
                continue;
            }
            $plan[] = $id;
            $seen[$id] = true;

            foreach ($childIdsByParent[$id] ?? [] as $childId) {
                if (!isset($seen[$childId])) {
                    $plan[] = $childId;
                    $seen[$childId] = true;
                }
            }
        }

        return $plan;
    }

    /**
     * Resolve the bounded set of base object ids for a catalog-object scope
     * WITHOUT hydrating entities. All → root ids of the type; Selected → the
     * requested ids; Filter → the DSL-matched ids. UUID v7 strings, ascending,
     * so the keyset walk in {@see runCatalogObjectToFile()} is deterministic.
     *
     * @return list<string>
     */
    private function resolveBaseIds(ExportSession $session, Tenant $tenant, ObjectType $objectType): array
    {
        $ids = match ($session->getTargetScope()) {
            ExportTargetScope::Selected => $this->selectedBaseIds($session),
            ExportTargetScope::All => $this->objects->findRootObjectIds($objectType, $tenant),
            ExportTargetScope::Filter => $this->resolveFilterIds($session, $tenant, $objectType),
        };

        sort($ids);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function selectedBaseIds(ExportSession $session): array
    {
        $ids = $session->getSelectedObjectIds();
        if (null === $ids || [] === $ids) {
            return [];
        }

        // Normalise to canonical RFC4122 (the session stores raw input) and
        // dedupe before the keyset walk.
        $normalised = [];
        foreach ($ids as $id) {
            $normalised[Uuid::fromString($id)->toRfc4122()] = true;
        }

        return array_keys($normalised);
    }

    /**
     * Compile the session's filter DSL into tenant-scoped SQL and return the
     * matching object ids (no hydration). Mirrors the pre-1632 resolveFilter()
     * SQL exactly, only stopping at ids instead of loading the entities.
     *
     * @return list<string>
     */
    private function resolveFilterIds(ExportSession $session, Tenant $tenant, ObjectType $objectType): array
    {
        $dsl = $session->getFilterSnapshot();
        if (null === $dsl || [] === $dsl) {
            return [];
        }

        $whereClause = $this->filterDsl->toCountSql($dsl);
        if (null === $whereClause) {
            throw new RuntimeException('Invalid filter DSL in export session snapshot.');
        }

        $tenantId = $tenant->getId()->toRfc4122();
        // EXR-05: scope by object_type_id (any ObjectType) instead of the
        // hardcoded Product kind. The DSL itself stays ObjectType-agnostic.
        // EXR-07: alias the table as `co` — FilterDslResolver emits
        // `co.`-prefixed column references, so the FROM clause must define it.
        $sql = 'SELECT co.id FROM objects co '
            .'WHERE co.tenant_id = :tenant AND co.object_type_id = :otid AND ('.$whereClause.')';

        try {
            $rows = $this->connection->fetchFirstColumn(
                $sql,
                ['tenant' => $tenantId, 'otid' => $objectType->getId()->toRfc4122()],
            );
        } catch (Throwable $error) {
            throw new RuntimeException('Filter scope SQL execution failed: '.$error->getMessage(), previous: $error);
        }

        $ids = [];
        foreach ($rows as $id) {
            if (\is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Resolve the ObjectType backing a catalog-object export session.
     *
     * Product sessions resolve the tenant's built-in Product type; custom
     * modules resolve their stored `object_type_id`. Structural entity types
     * are not catalog-object backed and must never reach this path (they are
     * rejected upstream by {@see ExportEntityType::isExecutable()}).
     */
    private function resolveObjectType(ExportSession $session, Tenant $tenant): ObjectType
    {
        return match ($session->getEntityType()) {
            ExportEntityType::Product => $this->objectTypes->findBuiltInByKind(ObjectKind::Product, $tenant)
                ?? throw new LogicException('Built-in Product ObjectType is not seeded for this tenant.'),
            ExportEntityType::CustomModule => $this->resolveCustomObjectType($session),
            default => throw new LogicException(sprintf(
                'Export entity_type "%s" is not backed by catalog objects.',
                $session->getEntityType()->value,
            )),
        };
    }

    private function resolveCustomObjectType(ExportSession $session): ObjectType
    {
        $id = $session->getObjectTypeId();
        if (null === $id) {
            throw new LogicException('custom_module export session is missing object_type_id.');
        }
        $objectType = $this->objectTypes->findById($id);
        if (null === $objectType) {
            throw new LogicException(sprintf('ObjectType "%s" was not found.', $id->toRfc4122()));
        }

        return $objectType;
    }

    /**
     * Run the export to a temporary file path and return that path.
     *
     * Caller (the controller) is responsible for streaming the file to
     * the HTTP response and deleting it afterwards. We split execution
     * from delivery so the same runner can later land async output.
     *
     * @param callable(int): void|null $onChunk EXR-15 — invoked every
     *                                          PROGRESS_CHUNK rows with rows-done; throwing
     *                                          {@see \App\Export\Application\Async\ExportCancelledException}
     *                                          aborts the run gracefully
     */
    public function runToFile(ExportSession $session, string $targetPath, ?callable $onChunk = null): int
    {
        if ($session->getEntityType()->isStructural()) {
            return $this->runStructuralToFile($session, $targetPath, $onChunk);
        }

        return $this->runCatalogObjectToFile($session, $targetPath, $onChunk);
    }

    /**
     * AUD-015 (#1632) — streaming export for EVERY catalog-object scope
     * (Selected / Filter / All, with or without include_variants). Resolves
     * the ordered emit-id plan once ({@see buildEmitIdPlan()}, id-only — no
     * hydration), then walks it in CLEAR_INTERVAL-sized keyset pages, hydrating
     * one page of objects at a time and clearing the EntityManager between
     * pages so the builder's per-object value/relation/category load never
     * accumulates. Replaces the pre-1632 split where only All-masters streamed
     * and Selected/Filter/All+variants materialised the whole object graph
     * (the OOM vector). Each page gets a FRESH {@see ExportBuilder::build()}
     * call against a clean EM; the inter-page clear detaches the session too,
     * so it is re-loaded each page (build() reads its tenant/channels) and
     * before the final markDone.
     *
     * @param (callable(int): void)|null $onChunk invoked every PROGRESS_CHUNK rows
     */
    private function runCatalogObjectToFile(ExportSession $session, string $targetPath, ?callable $onChunk): int
    {
        $sessionId = $session->getId();
        $tenant = $this->requireTenant($session);
        $tenantId = $tenant->getId();
        $objectType = $this->resolveObjectType($session, $tenant);

        $emitIds = $this->buildEmitIdPlan($session, $tenant, $objectType);
        // Size the run up-front (the async progress closure reads the in-memory
        // target count). Persisted at markDone, on a re-attached managed graph.
        $session->setTargetCount(\count($emitIds));

        $columns = $session->getSelectedColumns();
        if ([] === $columns) {
            // #1235 — derive columns from the publication profile of the
            // session's ObjectType (all scopes resolve a single type via
            // resolveObjectType; no need to scan the whole target set).
            $planned = $this->columnPlanner->plan($session, [$objectType->getId()->toRfc4122()]);
            if (null === $planned) {
                throw new InvalidArgumentException('Export session must list at least one column.');
            }
            $columns = $planned;
        }

        $sink = $this->openSink($session->getFormat(), $session, $columns, $targetPath);
        $sink->begin($columns);

        $rows = 0;
        try {
            foreach (array_chunk($emitIds, self::CLEAR_INTERVAL) as $idPage) {
                // build() needs a managed session each round (it reads the
                // session tenant/channels); re-attach a managed tenant after the
                // prior page's clear() detached it.
                $session = $this->reattachSession($session, $sessionId, $tenantId);
                // Hydrate just this page, restored to the plan's order
                // (findByIds returns DB order; the plan owns the contract).
                $page = $this->hydratePageInOrder($idPage);
                foreach ($this->builder->build($page, $session) as $row) {
                    $sink->accept($row);
                    ++$rows;
                    if (null !== $onChunk && 0 === $rows % self::PROGRESS_CHUNK) {
                        $onChunk($rows);
                    }
                }
                // Detach the page (objects + their hydrated values) before the next.
                $this->em->clear();
            }
        } finally {
            $sink->close();
        }

        $size = file_exists($targetPath) ? (int) filesize($targetPath) : 0;
        $session = $this->reattachSession($session, $sessionId, $tenantId);
        $session->markDone($rows, $targetPath, $size);
        $this->sessions->save($session);

        return $rows;
    }

    /**
     * Return a session whose tenant association is a MANAGED entity, so the
     * builder query path and the markDone save operate on a managed graph after
     * EntityManager::clear() detached everything. Prefers the persisted row
     * (async path saved it); for the not-yet-persisted sync path it re-attaches
     * a freshly-fetched managed tenant onto the in-memory session.
     */
    private function reattachSession(ExportSession $session, Uuid $sessionId, Uuid $tenantId): ExportSession
    {
        $reloaded = $this->sessions->findById($sessionId);
        if (null !== $reloaded) {
            return $reloaded;
        }

        $tenant = $this->em->find(Tenant::class, $tenantId->toRfc4122());
        if ($tenant instanceof Tenant) {
            $session->rebindTenant($tenant);
        }

        return $session;
    }

    /**
     * Hydrate one page of objects from their ids, preserving the order of
     * `$idPage` (the emit-id plan owns the master-then-variants contract;
     * findByIds returns arbitrary DB order). Ids with no surviving row (a
     * concurrent delete) are simply skipped.
     *
     * @param list<string> $idPage
     *
     * @return list<CatalogObject>
     */
    private function hydratePageInOrder(array $idPage): array
    {
        $byId = [];
        foreach ($this->objects->findByIds($idPage) as $object) {
            $byId[$object->getId()->toRfc4122()] = $object;
        }

        $ordered = [];
        foreach ($idPage as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * EXR-06: structural exports (module_schema / attributes_groups /
     * categories) stream a flat config table from the matching builder rather
     * than the catalog-object pipeline.
     */
    /**
     * @param callable(int): void|null $onChunk
     */
    private function runStructuralToFile(ExportSession $session, string $targetPath, ?callable $onChunk = null): int
    {
        $tenant = $this->requireTenant($session);
        $builder = $this->structuralBuilderFor($session->getEntityType());

        $columns = $session->getSelectedColumns();
        if ([] === $columns) {
            $columns = $builder->columns($tenant);
        }

        $sink = $this->openSink($session->getFormat(), $session, $columns, $targetPath);
        $sink->begin($columns);

        $rows = 0;
        try {
            foreach ($builder->rows($tenant) as $row) {
                $sink->accept($row);
                ++$rows;
                if (null !== $onChunk && 0 === $rows % self::PROGRESS_CHUNK) {
                    $onChunk($rows);
                }
            }
        } finally {
            $sink->close();
        }

        $session->setTargetCount($rows);
        $size = file_exists($targetPath) ? (int) filesize($targetPath) : 0;
        $session->markDone($rows, $targetPath, $size);
        $this->sessions->save($session);

        return $rows;
    }

    private function structuralBuilderFor(ExportEntityType $type): StructuralExportBuilderInterface
    {
        foreach ($this->structuralBuilders as $builder) {
            if ($builder->supports($type)) {
                return $builder;
            }
        }

        throw new LogicException(sprintf('No structural export builder supports entity_type "%s".', $type->value));
    }

    private function requireTenant(ExportSession $session): Tenant
    {
        $tenant = $session->getTenant();
        if (null === $tenant) {
            throw new LogicException('Export session must carry a tenant.');
        }

        return $tenant;
    }

    /**
     * Build the {@see RowSink} for the session format. XML (ADR-0023 §6.10)
     * streams through {@see GenericXmlWriter}, which needs the resolved column
     * plan (locale/channel scope → element attributes); CSV/XLSX wrap the
     * positional {@see RowWriter}.
     *
     * @param list<string> $columns
     */
    private function openSink(ExportFormat $format, ExportSession $session, array $columns, string $path): RowSink
    {
        if (ExportFormat::Xml === $format) {
            $definitions = array_values($this->columnResolver->resolve($columns, $session->getChannels() ?? []));

            return new XmlRowSink(new GenericXmlWriter(XmlWriterCore::toUri($path), $definitions));
        }

        return new PositionalRowSink($this->openWriter($format, $session, $path));
    }

    private function openWriter(ExportFormat $format, ExportSession $session, string $path): RowWriter
    {
        if (ExportFormat::Xlsx === $format) {
            $xlsx = new XlsxStreamWriter();
            $xlsx->openToFile($path);

            return $xlsx;
        }

        $encoding = $session->getEncoding() ?? ExportEncoding::Utf8Bom;
        $csv = new CsvStreamWriter();
        $csv->open($path, $encoding);

        return $csv;
    }
}
