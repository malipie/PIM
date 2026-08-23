<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\CreateCatalogObject;

use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Provenance;
use Symfony\Component\Uid\Uuid;

/**
 * Create a new {@see \App\Catalog\Domain\Entity\CatalogObject} aggregate.
 *
 * The command is the bridge between API Platform's input layer (per-kind
 * sugar paths `/api/products`, `/api/categories`, `/api/assets`) and the
 * domain. The expected `kind` is carried so the handler can reject a
 * payload whose `objectTypeId` resolves to an `ObjectType` of a different
 * kind (e.g. POST `/api/products` with a `category` ObjectType).
 */
final readonly class CreateCatalogObjectCommand
{
    /**
     * @param array<string, mixed> $attributes        per-attribute payload
     *                                                (`{code => value}`); empty
     *                                                array = no attributes upserted
     * @param list<Uuid>|null      $categoryIds       #891 atomic category assignment
     *                                                for `kind=product` creates.
     *                                                `null` = legacy behavior, no
     *                                                assignment. Empty list = 422.
     *                                                Each id must be a tenant-scoped
     *                                                `kind=category`.
     * @param Uuid|null            $primaryCategoryId required when
     *                                                $categoryIds non-empty;
     *                                                must appear in the list
     */
    public function __construct(
        public Uuid $objectTypeId,
        public string $code,
        public ObjectKind $expectedKind,
        public ?Uuid $parentId = null,
        public array $attributes = [],
        public ?array $categoryIds = null,
        public ?Uuid $primaryCategoryId = null,
        public ?Uuid $categoryTargetObjectTypeId = null,
        public Provenance $provenance = Provenance::Manual,
    ) {
    }
}
