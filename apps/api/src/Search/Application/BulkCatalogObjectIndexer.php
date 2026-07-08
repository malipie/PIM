<?php

declare(strict_types=1);

namespace App\Search\Application;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectCategory;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectCategoryRepositoryInterface;
use App\Search\Infrastructure\MeilisearchClientFactory;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Memory-safe bulk reindex of CatalogObject rows into Meilisearch
 * (#51 / 0.5.3).
 *
 * Iterates the entire catalog (or a single kind) using
 * `Query::toIterable()` + `EntityManager::clear()` every 200 rows so
 * the FrankenPHP worker stays under 256 MB even for 50k SKU. Documents
 * batch-push to Meili in chunks of 500 — one HTTP call instead of
 * 200 — keeping the round-trip count manageable on the 50k path.
 *
 * The progress + dry-run UX lives in the CLI (`SearchReindexCommand`);
 * this service is the reusable engine that the CLI, the bulk import
 * handler, and any future replay tool can share.
 */
final readonly class BulkCatalogObjectIndexer
{
    private const int FLUSH_CLEAR_INTERVAL = 200;
    private const int MEILI_BATCH_SIZE = 500;

    private LoggerInterface $logger;

    public function __construct(
        private EntityManagerInterface $em,
        private ObjectCategoryRepositoryInterface $objectCategories,
        private MeilisearchClientFactory $clientFactory,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param callable(int, int): void|null $onProgress (indexedSoFar, batchSize)
     *
     * @return array{count: int, batches: int}
     */
    public function reindex(?ObjectKind $kind = null, bool $dryRun = false, ?callable $onProgress = null): array
    {
        $client = $this->clientFactory->create();

        $qb = $this->em->createQueryBuilder()
            ->select('o')
            ->from(CatalogObject::class, 'o');

        // ULV-02 (#983) — every kind (custom included) writes to the
        // consolidated `objects` index. The previous custom-kind skip is
        // gone; the per-kind buffer collapses to a single flat buffer.
        if ($kind instanceof ObjectKind) {
            $qb->where('o.kind = :kind')->setParameter('kind', $kind);
        }
        $qb->orderBy('o.id', 'ASC');

        $count = 0;
        $batches = 0;
        $buffer = [];

        foreach ($qb->getQuery()->toIterable() as $row) {
            $buffer[] = $this->toDocument($row);
            ++$count;

            if (\count($buffer) >= self::MEILI_BATCH_SIZE) {
                $this->flushBatch($client, $buffer, $dryRun);
                $buffer = [];
                ++$batches;
                if (null !== $onProgress) {
                    $onProgress($count, self::MEILI_BATCH_SIZE);
                }
            }

            if (0 === $count % self::FLUSH_CLEAR_INTERVAL) {
                // Detach managed entities so Identity Map does not
                // accumulate; lessons #13 — flush() in loop without
                // clear() OOMs the FrankenPHP worker on 50k SKU.
                $this->em->clear();
            }
        }

        if ([] !== $buffer) {
            $this->flushBatch($client, $buffer, $dryRun);
            ++$batches;
            if (null !== $onProgress) {
                $onProgress($count, \count($buffer));
            }
        }

        return ['count' => $count, 'batches' => $batches];
    }

    /**
     * @param list<array<string, mixed>> $documents
     */
    private function flushBatch(\Meilisearch\Client $client, array $documents, bool $dryRun): void
    {
        if ($dryRun) {
            $this->logger->info('Reindex dry-run batch.', [
                'count' => \count($documents),
            ]);

            return;
        }

        try {
            $client->index(IndexSettingsTemplate::indexName())->addDocuments($documents, IndexSettingsTemplate::PRIMARY_KEY);
        } catch (Throwable $e) {
            $this->logger->warning('Reindex batch push failed: {message}', [
                'message' => $e->getMessage(),
                'count' => \count($documents),
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
            // Keep in lockstep with CatalogObjectIndexer::toDocument — the
            // reserved filterable `completeness_pct` powers the dashboard
            // drill-downs and the Red preset; the bulk reindex path must
            // emit it too or a full rebuild silently drops the field.
            'completeness_pct' => $object->getCompletenessPct(),
            'createdAt' => $object->getCreatedAt()->getTimestamp(),
            'updatedAt' => $object->getUpdatedAt()->getTimestamp(),
        ];
        // ULV-02 (#983) — any categorizable ObjectType gets its category
        // codes denormalised so the category-tree filter sidebar resolves
        // through Meili across kinds.
        if ($object->getObjectType()->isCategorizable()) {
            $base['category'] = array_map(
                static fn (ObjectCategory $a): string => $a->getCategory()->getCode(),
                $this->objectCategories->findByProduct($object),
            );
        }

        return array_merge(DocumentFlattener::flatten($attributesIndexed), $base);
    }
}
