<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Listener;

use App\Catalog\Domain\Entity\ObjectType;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * VIEW-01 (#372) — bumps `ObjectType.schema_version` whenever a setting
 * that reshapes the type's schema is mutated. The footer of the modeling
 * Detail view shows this counter as `model schema rev N`, giving
 * operators a visible signal that a change took effect.
 *
 * Bumps on:
 *   - hierarchical / hasVariants / abstract toggles,
 *   - allowedParentTypeIds,
 *   - completenessRules.
 *
 * Does NOT bump on cosmetic changes:
 *   - label (i18n strings),
 *   - icon, color (chrome-only).
 *
 * preUpdate runs after Doctrine computed the change set but before the
 * UPDATE is emitted; we mutate the entity, then call
 * `recomputeSingleEntityChangeSet` so the new `schema_version` column
 * lands in the same statement.
 */
#[AsDoctrineListener(event: Events::preUpdate)]
final class ObjectTypeSchemaVersionBumper
{
    /**
     * @var list<string>
     */
    private const array SCHEMA_FIELDS = [
        'hierarchical',
        'hasVariants',
        'abstract',
        'allowedParentTypeIds',
        'completenessRules',
        // DP-07 (#2037, ADR-0025) — cross-field rules shape the form contract.
        'validationRules',
    ];

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof ObjectType) {
            return;
        }

        $changeSet = $args->getEntityChangeSet();
        foreach (self::SCHEMA_FIELDS as $field) {
            if (\array_key_exists($field, $changeSet)) {
                $entity->bumpSchemaVersion();

                $em = $args->getObjectManager();
                $meta = $em->getClassMetadata(ObjectType::class);
                $em->getUnitOfWork()->recomputeSingleEntityChangeSet($meta, $entity);

                return;
            }
        }
    }
}
