<?php

declare(strict_types=1);

namespace App\Export\Application\Sync;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Export\Application\Builder\ColumnResolver;
use App\Export\Application\Builder\ExportBuilder;
use App\Export\Application\Builder\PublicationColumnPlanner;
use App\Export\Application\Builder\Structural\StructuralExportBuilderInterface;
use App\Export\Application\Scope\ExportScopeResolver;
use App\Export\Domain\Entity\ExportSession;
use App\Export\Domain\Enum\ExportEncoding;
use App\Export\Domain\Enum\ExportEntityType;
use App\Export\Domain\Enum\ExportFormat;
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
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

/**
 * Runs the catalog-object + structural export paths end-to-end.
 *
 * AUD-015 (#1632): catalog-object exports resolve an ordered emit-id PLAN via
 * {@see ExportScopeResolver} (id-only — no entity hydration) and stream it
 * through {@see ExportBuilder} in CLEAR_INTERVAL-sized
 * keyset pages, clearing the EntityManager between pages so a 50k export stays
 * in constant memory for EVERY scope (Selected / Filter / All) and both
 * include_variants states — not just the old All-masters fast path. Wraps the
 * file write in a try/finally so the temp file is cleaned up on partial failure.
 *
 * Filter/selection semantics live in ExportScopeResolver, shared with the
 * preflight endpoint so its count is the exact size of this runner's plan.
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
        private readonly ExportScopeResolver $scopeResolver,
        private readonly PublicationColumnPlanner $columnPlanner,
        private readonly ColumnResolver $columnResolver,
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

        return \count($this->scopeResolver->resolve($session)->objectIds);
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
     * the ordered emit-id plan once ({@see ExportScopeResolver}, id-only — no
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
        $resolvedScope = $this->scopeResolver->resolve($session);
        $emitIds = $resolvedScope->objectIds;
        // Size the run up-front (the async progress closure reads the in-memory
        // target count). Persisted at markDone, on a re-attached managed graph.
        $session->setTargetCount(\count($emitIds));

        $columns = $session->getSelectedColumns();
        if ([] === $columns) {
            // #1235 — derive columns from the publication profile of the
            // session's ObjectType (all scopes resolve a single type via
            // the resolved ObjectType; no need to scan the whole target set).
            $planned = $this->columnPlanner->plan($session, [$resolvedScope->objectTypeId->toRfc4122()]);
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
