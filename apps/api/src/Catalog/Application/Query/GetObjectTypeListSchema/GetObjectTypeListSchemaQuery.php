<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\GetObjectTypeListSchema;

use Symfony\Component\Uid\Uuid;

/**
 * ULV-03 (#984) — query for the universal list schema of an ObjectType.
 * Returns the columns (system + attribute, with `show_in_list=true`),
 * the set of filterable attribute codes, and the set of searchable
 * attribute codes — drives `ObjectListView` rendering and validates
 * incoming filter parameters server-side.
 */
final readonly class GetObjectTypeListSchemaQuery
{
    public function __construct(
        public Uuid $objectTypeId,
        /**
         * GRID-P3-01 (#2392) — full catalogue mode: columns include EVERY
         * attribute attached to the ObjectType (not only `show_in_list`),
         * each flagged with `default` and its AttributeGroup projection,
         * so the column manager can offer any attribute as a column.
         */
        public bool $full = false,
    ) {
    }
}
