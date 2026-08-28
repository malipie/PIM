<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Contracts\Service\AttributesIndexedBatchRebuilder as AttributesIndexedBatchRebuilderContract;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectValueRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** Catalog-owned implementation of the cross-context batch-rebuild seam. */
final readonly class AttributesIndexedBatchRebuilder implements AttributesIndexedBatchRebuilderContract
{
    public function __construct(
        private EntityManagerInterface $em,
        private CatalogObjectRepositoryInterface $objects,
        private ObjectValueRepositoryInterface $values,
        private AttributesIndexedRebuilder $rebuilder,
    ) {
    }

    public function rebuild(array $objectIds): void
    {
        if ([] === $objectIds) {
            return;
        }

        $objectsById = [];
        foreach ($this->objects->findByIds($objectIds) as $object) {
            $objectsById[$object->getId()->toRfc4122()] = $object;
        }

        // Load values only after the objects. The ObjectValue association can
        // otherwise seed Doctrine's identity map with lazy object proxies and
        // defeat the batch hydration above.
        $valuesByObject = $this->values->findByObjectIds(
            array_map(static fn (string $id): Uuid => Uuid::fromString($id), $objectIds),
        );

        foreach ($objectIds as $id) {
            $object = $objectsById[$id] ?? null;
            if ($object instanceof CatalogObject) {
                $this->rebuilder->rebuild($object, $valuesByObject[$id] ?? []);
            }
        }

        $this->em->flush();
        $this->em->clear();
    }
}
