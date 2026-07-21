<?php

declare(strict_types=1);

namespace App\Catalog\Application\Filter;

/**
 * #2673 — per-attribute metadata needed by the scoped filter path: the
 * attribute id keys the `object_values` subselect (code→uuid resolved once
 * in PHP, no JOIN in the correlated query), the capability flags decide
 * which scope dimensions apply to a condition.
 */
final readonly class AttributeMeta
{
    public function __construct(
        /** RFC 4122 string form — inlined into the parameter-free SQL. */
        public string $id,
        public string $type,
        public bool $localizable,
        public bool $scopable,
    ) {
    }
}
