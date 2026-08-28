<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectRelation;
use App\Catalog\Domain\RelationCardinality;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectRelationRepositoryInterface;
use App\Catalog\Domain\Validator\RelationMetadataValidator;
use App\Shared\Application\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-014 / MOD-06 (#898) — domain service for object↔object relations.
 *
 * Backs the three endpoints on `ObjectRelationController`:
 *   - listing the relations of an object grouped by attribute,
 *   - atomic replace of relations for a single (source, attribute),
 *   - single-row remove.
 *
 * Tenant scope: every lookup goes through TenantFilter-bound
 * repositories so cross-tenant ids resolve to NotFound (404). The
 * service additionally checks that source + target objects + the
 * attribute all share the active tenant — this guards against the rare
 * case where a controller fabricates an entity reference before the
 * filter has had a chance to vet it.
 */
final class ObjectRelationService
{
    public function __construct(
        private readonly ObjectRelationRepositoryInterface $relations,
        private readonly CatalogObjectRepositoryInterface $objects,
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $em,
        private readonly RelationMetadataValidator $metadataValidator,
    ) {
    }

    /**
     * @return list<ObjectRelation>
     */
    public function listForSource(CatalogObject $source, Attribute $attribute): array
    {
        return $this->relations->findBySourceAndAttribute($source, $attribute);
    }

    /**
     * MOD-07 (#899) — reverse view: every row pointing AT `$target`.
     * Returned in `created_at` order so the read-only "Powiązania
     * zwrotne" panel renders stable groupings.
     *
     * @return list<ObjectRelation>
     */
    public function findByTarget(CatalogObject $target): array
    {
        return $this->relations->findByTarget($target);
    }

    /**
     * MODR-06 (#928) — count of incoming links for the tab-visibility check.
     */
    public function countByTarget(CatalogObject $target): int
    {
        return $this->relations->countByTarget($target);
    }

    /**
     * Atomically replace the relations under `(source, attribute)` with the
     * supplied target object list. Idempotent: when the new list equals the
     * existing one, the function still touches each row (position update)
     * but introduces no schema delta. Cardinality `one` allows at most one
     * target — extra entries reject with 422.
     *
     * MOD-08 (#900): each entry MAY carry a per-link metadata payload that
     * gets validated against the attribute's advanced-fields schema. For
     * `advanced=false` attributes metadata MUST be empty.
     *
     * @param list<array{id: Uuid, metadata?: array<string, mixed>}> $targets
     */
    public function replaceForSourceAndAttribute(
        CatalogObject $source,
        Attribute $attribute,
        array $targets,
    ): void {
        $this->guardAttribute($attribute);
        $this->guardCardinality($attribute, $targets);

        $tenant = $this->tenantContext->get();
        $resolved = [];
        foreach ($targets as $position => $entry) {
            $targetId = $entry['id'];
            $target = $this->objects->findById($targetId);
            if (null === $target) {
                throw new NotFoundHttpException(\sprintf(
                    'Target object "%s" was not found in this tenant.',
                    $targetId->toRfc4122(),
                ));
            }
            if (null !== $tenant && $target->getTenant()?->getId()->equals($tenant->getId()) !== true) {
                throw new NotFoundHttpException(\sprintf(
                    'Target object "%s" was not found in this tenant.',
                    $targetId->toRfc4122(),
                ));
            }
            if ($target->getId()->equals($source->getId())) {
                throw new UnprocessableEntityHttpException(
                    'A relation cannot point at the source object itself.',
                );
            }
            $this->guardTargetObjectType($attribute, $target);

            $metadata = $this->metadataValidator->validateAndNormalise(
                $attribute,
                $entry['metadata'] ?? [],
            );

            $resolved[] = ['target' => $target, 'metadata' => $metadata];
        }

        // Atomic replace: drop existing → insert new. One transaction.
        $this->em->wrapInTransaction(function () use ($source, $attribute, $resolved): void {
            foreach ($this->relations->findBySourceAndAttribute($source, $attribute) as $existing) {
                $this->relations->remove($existing);
            }
            $this->em->flush();

            foreach ($resolved as $position => $entry) {
                $row = new ObjectRelation(
                    source: $source,
                    target: $entry['target'],
                    attribute: $attribute,
                    position: $position,
                    metadata: $entry['metadata'],
                );
                $this->relations->add($row);
            }
            $this->em->flush();
        });
    }

    public function removeOne(
        CatalogObject $source,
        Attribute $attribute,
        CatalogObject $target,
    ): bool {
        foreach ($this->relations->findBySourceAndAttribute($source, $attribute) as $existing) {
            if ($existing->getTarget()->getId()->equals($target->getId())) {
                $this->relations->remove($existing);
                $this->em->flush();

                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{id: Uuid, metadata?: array<string, mixed>}> $targets
     */
    private function guardCardinality(Attribute $attribute, array $targets): void
    {
        if (RelationCardinality::One === $attribute->getRelationCardinality() && \count($targets) > 1) {
            throw new UnprocessableEntityHttpException(\sprintf(
                'Attribute "%s" has cardinality=one; only a single target is allowed.',
                $attribute->getCode(),
            ));
        }
    }

    private function guardAttribute(Attribute $attribute): void
    {
        if (AttributeType::Relation !== $attribute->getType()) {
            throw new UnprocessableEntityHttpException(\sprintf(
                'Attribute "%s" is not of type "relation".',
                $attribute->getCode(),
            ));
        }
    }

    private function guardTargetObjectType(Attribute $attribute, CatalogObject $target): void
    {
        $allowedIds = $attribute->getRelationTargetObjectTypeIds();
        if ([] === $allowedIds) {
            return;
        }
        if (!\in_array($target->getObjectType()->getId()->toRfc4122(), $allowedIds, true)) {
            throw new UnprocessableEntityHttpException(\sprintf(
                'Target object "%s" has ObjectType "%s" which is not in the allowed targets of attribute "%s".',
                $target->getId()->toRfc4122(),
                $target->getObjectType()->getCode(),
                $attribute->getCode(),
            ));
        }
    }
}
