<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\DeleteCatalogObject;

use App\Catalog\Application\Reindex\BulkReindexQueueInterface;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DeleteCatalogObjectHandler
{
    public function __construct(
        private CatalogObjectRepositoryInterface $catalogObjects,
        private BulkReindexQueueInterface $reindexQueue,
    ) {
    }

    public function __invoke(DeleteCatalogObjectCommand $command): void
    {
        $object = $this->catalogObjects->findById($command->id);
        if (null === $object) {
            throw new NotFoundHttpException(\sprintf(
                'CatalogObject "%s" was not found.',
                $command->id->toRfc4122(),
            ));
        }

        $id = $object->getId()->toRfc4122();
        $kind = $object->getKind();

        $this->catalogObjects->remove($object);

        // #2986 — the source row is gone after remove(), so an ordinary
        // upsert cannot heal the search projection. Queue an explicit delete
        // while the id + kind are still available; kernel.terminate (HTTP),
        // console.terminate (CLI) or the worker drain middleware ships it to
        // Meilisearch through the same collector used by bulk deletes.
        $this->reindexQueue->queueAllDeleted([$id], $kind);
    }
}
