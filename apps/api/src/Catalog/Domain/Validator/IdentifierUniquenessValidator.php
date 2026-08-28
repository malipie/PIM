<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Validator;

use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Repository\IdentifierDuplicateQueryInterface;

/**
 * #1179 — application-level pre-check for `identifier` value uniqueness
 * within one ObjectType, so the API returns a clean 409 before hitting
 * the DB-level partial unique index (the index remains the race-proof
 * source of truth).
 *
 * Delegates to a persistence-independent query port whose Doctrine adapter
 * reads the trigger-maintained denormalised columns, so the lookup rides the
 * `object_values_identifier_uniq` index. Existing
 * identifier rows always have the columns populated (the trigger runs on
 * every write); the object being saved is excluded by id so re-saving the
 * same value does not collide with itself.
 *
 * Returns `true` when `$value` is already used by another object of the
 * same ObjectType for the same attribute; `false` when it is free.
 */
final readonly class IdentifierUniquenessValidator
{
    public function __construct(
        private IdentifierDuplicateQueryInterface $duplicates,
    ) {
    }

    public function isDuplicate(CatalogObject $object, Attribute $attribute, string $value): bool
    {
        return $this->duplicates->isDuplicate($object, $attribute, $value);
    }
}
