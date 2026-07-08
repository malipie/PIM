<?php

declare(strict_types=1);

namespace App\Search\Application;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectCategory;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectCategoryRepositoryInterface;
use App\Search\Infrastructure\MeilisearchClientFactory;
use App\Shared\Domain\Tenant;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Index a single `CatalogObject` into the matching Meilisearch index
 * (#50 / 0.5.2).
 *
 * One document per row, identified by the row's Uuid. Fields cover:
 *   - identity (`id`, `code`, `kind`)
 *   - editorial state (`status`, `enabled`)
 *   - parent / path for hierarchy lookups
 *   - timestamps for sortable lists
 *   - flat attributesIndexed snapshot (denormalised cache from #38)
 *
 * Tenant filtering is delegated to a `tenantId` filterable attribute —
 * read-side queries from `/api/products/search` (#52) inject the
 * authenticated user's tenant before hitting Meili.
 *
 * Hub failures are logged and swallowed (same fail-soft pattern as
 * MercurePublisher #47): search is a notification surface, not the
 * source of truth.
 */
final readonly class CatalogObjectIndexer
{
    /**
     * Hard cap per Meili HTTP call. The per-request collector
     * (PROD-03) chunks large drains into multiple `addDocuments`
     * calls so we never push more than this in one round-trip.
     */
    private const int BATCH_SIZE = 1000;

    private LoggerInterface $logger;

    public function __construct(
        private CatalogObjectRepositoryInterface $catalogObjects,
        private ObjectCategoryRepositoryInterface $objectCategories,
        private MeilisearchClientFactory $clientFactory,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function index(Uuid $objectId): void
    {
        $object = $this->catalogObjects->findById($objectId);
        if (null === $object) {
            $this->logger->warning('Indexer skipped — CatalogObject not found.', ['id' => $objectId->toRfc4122()]);

            return;
        }

        // ULV-02 (#983) — custom kinds land in the consolidated `objects`
        // index alongside built-ins; the universal list view treats every
        // ObjectType uniformly. The previous Phase 2/3 skip is gone.
        $this->push($object);
    }

    /**
     * PROD-03 — batch path used by {@see CatalogIndexFlushSubscriber}
     * after a request finishes. Loads all objects in one query, groups
     * by kind, then issues one `addDocuments` per kind (chunked at
     * {@see self::BATCH_SIZE}). Single-object requests still go through
     * {@see self::index()} so the existing call sites don't change.
     *
     * @param list<string> $idsRfc4122
     */
    public function indexBatch(array $idsRfc4122): void
    {
        if ([] === $idsRfc4122) {
            return;
        }

        $objects = $this->catalogObjects->findByIds($idsRfc4122);
        if ([] === $objects) {
            return;
        }

        // ULV-02 (#983) — single `objects` index for every ObjectKind
        // (custom included), so the by-kind partition is gone. The early
        // empty-$objects guard above keeps this list non-empty.
        $documents = array_map(fn ($o) => $this->toDocument($o), $objects);

        try {
            $client = $this->clientFactory->create();
        } catch (Throwable $e) {
            // Fail-soft when Meilisearch is unreachable / unconfigured —
            // search is a notification surface (lessons #50). The factory
            // throws when MEILI_URL is missing (test envs) or when the
            // hub is down; either way the next single-row event or the
            // scheduled reindex will recover document state.
            $this->logger->warning('Index batch push skipped — client unavailable: {message}', [
                'message' => $e->getMessage(),
            ]);

            return;
        }

        foreach (array_chunk($documents, self::BATCH_SIZE) as $chunk) {
            try {
                $client->index(IndexSettingsTemplate::indexName())->addDocuments($chunk, IndexSettingsTemplate::PRIMARY_KEY);
            } catch (Throwable $e) {
                // Fail-soft per chunk — same rationale as above.
                $this->logger->warning('Index batch push failed: {message}', [
                    'message' => $e->getMessage(),
                    'count' => \count($chunk),
                ]);
            }
        }
    }

    public function remove(Uuid $objectId, ObjectKind $kind): void
    {
        // ULV-02 (#983) — single `objects` index; the `$kind` argument is
        // legacy hint kept for telemetry and call-site BC.
        try {
            $client = $this->clientFactory->create();
            $client->index(IndexSettingsTemplate::indexName())->deleteDocument($objectId->toRfc4122());
        } catch (Throwable $e) {
            $this->logger->warning('Index delete failed: {message}', [
                'message' => $e->getMessage(),
                'id' => $objectId->toRfc4122(),
                'kind' => $kind->value,
            ]);
        }
    }

    /**
     * PROD-03 — batch delete companion to {@see self::indexBatch()}.
     *
     * @param array<string, ObjectKind> $kindByIdRfc4122
     */
    public function removeBatch(array $kindByIdRfc4122): void
    {
        if ([] === $kindByIdRfc4122) {
            return;
        }

        // ULV-02 (#983) — single `objects` index; kind partition replaced
        // by a flat list of ids. Custom kinds are included now.
        // The early `[] === $kindByIdRfc4122` guard above keeps this
        // non-empty.
        $ids = array_keys($kindByIdRfc4122);

        try {
            $client = $this->clientFactory->create();
        } catch (Throwable $e) {
            $this->logger->warning('Index batch delete skipped — client unavailable: {message}', [
                'message' => $e->getMessage(),
            ]);

            return;
        }

        try {
            $client->index(IndexSettingsTemplate::indexName())->deleteDocuments($ids);
        } catch (Throwable $e) {
            $this->logger->warning('Index batch delete failed: {message}', [
                'message' => $e->getMessage(),
                'count' => \count($ids),
            ]);
        }
    }

    private function push(CatalogObject $object): void
    {
        try {
            $client = $this->clientFactory->create();
            $client->index(IndexSettingsTemplate::indexName())
                ->addDocuments([$this->toDocument($object)], IndexSettingsTemplate::PRIMARY_KEY);
        } catch (Throwable $e) {
            $this->logger->warning('Index push failed: {message}', [
                'message' => $e->getMessage(),
                'id' => $object->getId()->toRfc4122(),
                'kind' => $object->getKind()->value,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function toDocument(CatalogObject $object): array
    {
        $tenant = $object->getTenant();
        \assert($tenant instanceof Tenant);

        $attributesIndexed = $object->getAttributesIndexed();

        $base = [
            'id' => $object->getId()->toRfc4122(),
            'tenantId' => $tenant->getId()->toRfc4122(),
            'code' => $object->getCode(),
            'kind' => $object->getKind()->value,
            'objectTypeId' => $object->getObjectType()->getId()->toRfc4122(),
            'status' => $object->getStatus(),
            'enabled' => $object->isEnabled(),
            'parentId' => $object->getParent()?->getId()->toRfc4122(),
            'path' => $object->getPath(),
            'attributesIndexed' => $attributesIndexed,
            'completeness' => $object->getCompleteness(),
            // `completeness_pct` is a reserved filterable attribute
            // (IndexSettingsTemplate) that powers the dashboard drill-downs
            // (`completeness_pct >= 80`) and the Red preset. Emit the
            // denormalised smallint column so those filters actually match —
            // the `completeness` JSONB blob above is not filterable in Meili.
            'completeness_pct' => $object->getCompletenessPct(),
            'createdAt' => $object->getCreatedAt()->getTimestamp(),
            'updatedAt' => $object->getUpdatedAt()->getTimestamp(),
        ];
        // Category denormalisation: any ObjectType with `isCategorizable=true`
        // gets its category code array surfaced so the universal list view's
        // category-tree filter (ULV-09) resolves through Meili.
        if ($object->getObjectType()->isCategorizable()) {
            $base['category'] = array_map(
                static fn (ObjectCategory $a): string => $a->getCategory()->getCode(),
                $this->objectCategories->findByProduct($object),
            );
        }

        return array_merge(DocumentFlattener::flatten($attributesIndexed), $base);
    }
}
