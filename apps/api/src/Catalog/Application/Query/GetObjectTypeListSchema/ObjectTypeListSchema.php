<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\GetObjectTypeListSchema;

/**
 * Read-side projection returned by {@see GetObjectTypeListSchemaHandler}.
 *
 * Columns follow the standard system → attribute order:
 *   - `system: true` columns (identifier/code, status, completeness,
 *     updatedAt) always render, in fixed order, on every ObjectType list.
 *   - `system: false` columns are attribute rows with
 *     `object_type_attributes.show_in_list = true`, ordered by
 *     `list_position` (then attribute label as a tie-breaker).
 *
 * `filterableAttributes` and `searchableAttributes` are the attribute
 * codes the universal endpoint accepts for `?filter[...]` and `?q=`
 * respectively; the FE uses them to gate filter chip rendering, the BE
 * uses them to reject unsupported filter params with 400 / Problem
 * Details.
 *
 * @phpstan-type ColumnRow array{
 *     key: string,
 *     type: string,
 *     label: array<string, string>,
 *     position: int,
 *     sortable: bool,
 *     editable: bool,
 *     system: bool,
 *     default?: bool,
 *     group?: array{id: string, code: string, label: array<string, string>, position: int}|null
 * }
 */
final readonly class ObjectTypeListSchema
{
    /**
     * @param array{id: string, code: string, kind: string, label: array<string, string>, is_categorizable: bool, has_variants: bool, has_multimedia: bool, expose_to_main_menu: bool} $objectType
     * @param list<ColumnRow>                                                                                                                                                          $columns
     * @param list<string>                                                                                                                                                             $filterableAttributes
     * @param list<string>                                                                                                                                                             $searchableAttributes
     */
    public function __construct(
        public array $objectType,
        public array $columns,
        public array $filterableAttributes,
        public array $searchableAttributes,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'objectType' => $this->objectType,
            'columns' => $this->columns,
            'filterableAttributes' => $this->filterableAttributes,
            'searchableAttributes' => $this->searchableAttributes,
        ];
    }
}
