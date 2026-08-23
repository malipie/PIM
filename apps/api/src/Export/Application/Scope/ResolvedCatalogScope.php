<?php

declare(strict_types=1);

namespace App\Export\Application\Scope;

use Symfony\Component\Uid\Uuid;

/** Exact, ordered catalog-object scope used by export preflight and execution. */
final readonly class ResolvedCatalogScope
{
    /** @param list<string> $objectIds RFC 4122 ids in final file order */
    public function __construct(
        public Uuid $objectTypeId,
        public array $objectIds,
    ) {
    }
}
