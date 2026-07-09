<?php

declare(strict_types=1);

namespace App\Export\Catalog\Application;

/**
 * Outcome of one catalog render (ADR-0027, CPDF-P2-03): the page count, the
 * byte size of the produced PDF, and how many products were rendered. Immutable
 * value object handed back to the caller so it can record the cache metadata on
 * the {@see \App\Export\Catalog\Domain\Entity\CatalogProfile} / CatalogRun.
 */
final class CatalogRenderResult
{
    public function __construct(
        public readonly int $pageCount,
        public readonly int $byteSize,
        public readonly int $itemCount,
    ) {
    }
}
