<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectCategory;
use App\Catalog\Domain\Repository\ObjectCategoryRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * #2737 — category assignment for imported rows: resolve the row's codes into
 * entities, apply the chunk's replace/append operations and append without
 * duplicating an existing link.
 *
 * Pulled out of {@see \App\Import\Application\Handler\ImportRunHandler},
 * where it sat between the chunk loop and the media pipeline. Behaviour is
 * unchanged — the operations still run inside the handler's chunk transaction,
 * which is why this collaborator takes the same EntityManager rather than
 * flushing on its own.
 */
final readonly class ImportCategoryOps
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ObjectCategoryRepositoryInterface $objectCategories,
    ) {
    }

    /**
     * @param list<string>                 $codes
     * @param array<string, CatalogObject> $categoryByCode
     *
     * @return list<CatalogObject>
     */
    public function resolveCategories(array $codes, array $categoryByCode): array
    {
        $out = [];
        foreach ($codes as $code) {
            if (isset($categoryByCode[$code])) {
                $out[] = $categoryByCode[$code];
            }
        }

        return $out;
    }

    /**
     * @param list<array{product: CatalogObject, codes: list<string>, append: bool}> $ops
     * @param array<string, CatalogObject>                                           $categoryByCode
     */
    public function applyCategoryOps(array $ops, array $categoryByCode): void
    {
        foreach ($ops as $op) {
            $categories = $this->resolveCategories($op['codes'], $categoryByCode);
            if ([] === $categories) {
                // All codes unresolved — leave existing assignments untouched
                // rather than wiping a product from a typo (D2-safe; per-code
                // warnings already surfaced).
                continue;
            }

            if ($op['append']) {
                $this->appendCategories($op['product'], $categories);

                continue;
            }

            $ids = array_map(static fn (CatalogObject $c): Uuid => $c->getId(), $categories);
            $this->objectCategories->replaceForProduct($op['product'], $ids, $ids[0]);
        }
    }

    /**
     * Append categories to an object's existing assignments without
     * duplicates (D2 append policy). Position continues after the current
     * max; if the object had no primary, the first appended becomes primary.
     *
     * @param list<CatalogObject> $categories
     */
    public function appendCategories(CatalogObject $product, array $categories): void
    {
        $existingIds = [];
        $maxPosition = -1;
        $hasPrimary = false;
        foreach ($this->objectCategories->findByProduct($product) as $assignment) {
            $existingIds[$assignment->getCategory()->getId()->toRfc4122()] = true;
            $maxPosition = max($maxPosition, $assignment->getPosition());
            $hasPrimary = $hasPrimary || $assignment->isPrimary();
        }

        $position = $maxPosition + 1;
        foreach ($categories as $category) {
            if (isset($existingIds[$category->getId()->toRfc4122()])) {
                continue;
            }
            $primary = !$hasPrimary;
            $this->entityManager->persist(new ObjectCategory(
                product: $product,
                category: $category,
                isPrimary: $primary,
                position: $position++,
            ));
            $hasPrimary = $hasPrimary || $primary;
        }
    }
}
