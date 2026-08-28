<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Service;

/** Runs cross-context work while Catalog's per-object listeners are muted. */
interface BulkOperationScope
{
    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function run(callable $operation): mixed;
}
