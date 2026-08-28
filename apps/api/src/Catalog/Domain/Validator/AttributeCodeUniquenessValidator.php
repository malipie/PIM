<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Validator;

use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Repository\AttributeCodeConflictQueryInterface;

/**
 * ADR-014 / MOD-04 (#896) — checks that an Attribute code is unique within
 * the **effective attribute model** of a target ObjectType.
 *
 * The effective model has two layers per ADR-014:
 *   1. **Base** — `object_type_attributes` junction. Attributes directly
 *      attached to the ObjectType.
 *   2. **Category-distributed** — `category_attribute_groups` whose
 *      `target_object_type_id` points at this ObjectType, transitively
 *      including every Attribute attached to each such AttributeGroup via
 *      `attribute_group_attributes`.
 *
 * The validator runs at attach-time (before adding an attribute to an OT's
 * base or to a group that distributes to that OT). It does NOT replace the
 * existing tenant-wide unique constraint on `attributes(tenant_id, code)`
 * — that constraint still guards against two attribute rows with the same
 * code in one tenant. This validator narrows the check to a single OT's
 * effective model so the operator sees a precise error message
 * (`existing_location` distinguishes "already in base" from "comes from
 * category X").
 *
 * Returns the {@see AttributeCodeConflict} on collision, `null` when the
 * code is free in the model. Callers translate the conflict into a
 * Problem Details 422 response.
 */
final readonly class AttributeCodeUniquenessValidator
{
    public function __construct(
        private AttributeCodeConflictQueryInterface $conflicts,
    ) {
    }

    public function validate(
        string $code,
        ObjectType $objectType,
        ?Attribute $excludeAttribute = null,
    ): ?AttributeCodeConflict {
        $baseConflict = $this->conflicts->findBaseConflict($code, $objectType, $excludeAttribute);
        if (null !== $baseConflict) {
            return $baseConflict;
        }

        return $this->conflicts->findCategoryConflict($code, $objectType, $excludeAttribute);
    }
}
