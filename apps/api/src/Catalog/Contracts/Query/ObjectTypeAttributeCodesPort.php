<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Query;

use Symfony\Component\Uid\Uuid;

/**
 * #2943 — cross-BC read of "which attribute codes does this ObjectType
 * model", i.e. the same set the import mapping step offers as targets.
 *
 * Import needs it to decide whether a row must carry `sku`: requiring it
 * from a custom ObjectType that does not model it failed every row on a
 * column the operator had no way to supply. Deptrac lets `Import` reach
 * `Catalog_Contracts` only, so the lookup goes through this port rather
 * than `ObjectTypeAttributeRepositoryInterface` directly.
 *
 * Codes only: the caller asks a membership question, not for the attribute
 * definitions, and a narrower answer is a narrower coupling.
 */
interface ObjectTypeAttributeCodesPort
{
    /**
     * Attribute codes attached directly to the given ObjectType. Empty for
     * an unknown id — the caller reads that as "models nothing", which is
     * the same answer it would get for a type with no attributes.
     *
     * @return list<string>
     */
    public function forObjectType(Uuid $objectTypeId): array;
}
