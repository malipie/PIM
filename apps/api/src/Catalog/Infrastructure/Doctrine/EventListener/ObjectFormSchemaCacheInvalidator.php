<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\EventListener;

use App\Catalog\Application\Query\GetObjectFormSchema\GetObjectFormSchemaHandler;
use App\Catalog\Application\Query\Usage\UsageQueryService;
use App\Catalog\Domain\Entity\AttributeGroupAttribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\CategoryAttributeGroup;
use App\Catalog\Domain\Entity\ObjectCategory;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\Entity\ObjectTypeAttributeGroup;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * UI-08.4 (#259) — invalidates the form-schema cache when the underlying
 * AttributeGroup wiring changes.
 *
 * `EffectiveAttributeGroupResolver` reads three tables: `object_type_-
 * attribute_groups` (global), `category_attribute_groups` (inherited),
 * and `attribute_group_attributes` (members of a group). Mutations on
 * any of those tables invalidate every cached form schema for the
 * affected ObjectType — we cannot narrow the invalidation further
 * without tracking which schemas referenced which junction (would
 * require a reverse index that doesn't pay for itself in MVP).
 *
 * Strategy: tag every cache entry with `pim_form_schema` (global tag) +
 * `pim_form_schema.object_type.<id>` (per-type tag). On mutation:
 *   - ObjectTypeAttributeGroup change → invalidate the per-type tag.
 *   - CategoryAttributeGroup change → invalidate the per-type tag for
 *     `targetObjectType` (the type that inherits this group).
 *   - AttributeGroupAttribute change → invalidate the global tag (a
 *     change to a group's member set ripples to every schema that
 *     includes that group; the per-type tags are insufficient).
 *   - ObjectCategory change (PCAT-04) → invalidate the per-type tag
 *     for the affected product's ObjectType. PCAT-03 made products
 *     inherit category-declared groups, so toggling an assignment
 *     changes the resolved schema for every product of that type.
 *     Trade-off accepted: we burst the cache for *all* products of
 *     the type, not just the one that changed (per-object cache key
 *     is a Faza 1.1 follow-up).
 *
 * Defers actual invalidation to `postFlush` so a single transaction
 * touching N rows triggers one cache invalidation per affected tag set.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ObjectFormSchemaCacheInvalidator
{
    /**
     * @var array<string, true>
     */
    private array $perTypeTags = [];

    /**
     * @var array<string, true>
     */
    private array $usageTags = [];

    private bool $globalInvalidate = false;

    public function __construct(
        private readonly TagAwareCacheInterface $modelingCache,
    ) {
    }

    public function postPersist(PostPersistEventArgs $event): void
    {
        $entity = $event->getObject();
        $this->collect($entity);
        $this->collectCatalogObjectUsage($entity);
    }

    public function postUpdate(PostUpdateEventArgs $event): void
    {
        $this->collect($event->getObject());
    }

    public function postRemove(PostRemoveEventArgs $event): void
    {
        $entity = $event->getObject();
        $this->collect($entity);
        $this->collectCatalogObjectUsage($entity);
    }

    public function postFlush(PostFlushEventArgs $event): void
    {
        if (!$this->globalInvalidate && [] === $this->perTypeTags && [] === $this->usageTags) {
            return;
        }

        $tags = [];
        if ($this->globalInvalidate) {
            $tags[] = GetObjectFormSchemaHandler::CACHE_TAG;
            // UI-08.7 (#262) — usage counts derive from the same junction
            // tables, so any AttributeGroupAttribute mutation also
            // invalidates every usage cache entry.
            $tags[] = UsageQueryService::CACHE_TAG;
        }
        foreach (array_keys($this->perTypeTags) as $typeId) {
            $tags[] = GetObjectFormSchemaHandler::CACHE_TAG.'.object_type.'.$typeId;
            $tags[] = UsageQueryService::CACHE_TAG.'.object_type.'.$typeId;
        }
        foreach (array_keys($this->usageTags) as $tag) {
            $tags[] = $tag;
        }

        $this->globalInvalidate = false;
        $this->perTypeTags = [];
        $this->usageTags = [];

        if ([] !== $tags) {
            $this->modelingCache->invalidateTags(array_unique($tags));
        }
    }

    private function collect(object $entity): void
    {
        if ($entity instanceof ObjectTypeAttributeGroup) {
            $this->perTypeTags[$entity->getObjectType()->getId()->toRfc4122()] = true;

            return;
        }

        if ($entity instanceof ObjectTypeAttribute) {
            $typeId = $entity->getObjectType()->getId()->toRfc4122();
            $attributeId = $entity->getAttribute()->getId()->toRfc4122();
            $this->perTypeTags[$typeId] = true;
            $this->usageTags[UsageQueryService::CACHE_TAG.'.attribute.'.$attributeId] = true;

            return;
        }

        if ($entity instanceof CategoryAttributeGroup) {
            $this->perTypeTags[$entity->getTargetObjectType()->getId()->toRfc4122()] = true;

            return;
        }

        if ($entity instanceof AttributeGroupAttribute) {
            // A group's member set changed — every schema that includes
            // this group must rebuild. Global flush is the simplest
            // correct invalidation in MVP.
            $this->globalInvalidate = true;

            return;
        }

        if ($entity instanceof ObjectCategory) {
            // PCAT-04: assigning/detaching a category from a product
            // shifts the resolved group set for that product (PCAT-03
            // resolver branch). Invalidate the per-type tag — bursts
            // cache for all products of the type, but per-object
            // granularity is a Faza 1.1 follow-up.
            $product = $entity->getProduct();
            $this->perTypeTags[$product->getObjectType()->getId()->toRfc4122()] = true;
        }
    }

    private function collectCatalogObjectUsage(object $entity): void
    {
        if (!$entity instanceof CatalogObject) {
            return;
        }

        $typeId = $entity->getObjectType()->getId()->toRfc4122();
        $this->usageTags[UsageQueryService::CACHE_TAG.'.object_type.'.$typeId] = true;
    }
}
