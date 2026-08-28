<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Service;

/** Public Catalog seam for rebuilding denormalised object attributes in bulk. */
interface AttributesIndexedBatchRebuilder
{
    /** @param list<string> $objectIds RFC4122 */
    public function rebuild(array $objectIds): void;
}
