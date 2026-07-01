<?php

declare(strict_types=1);

namespace App\Export\Contracts;

/**
 * Seam over the product value source a feed projects (ADR-0023 §6.3, XMLF-P2-04
 * / P3-02). Yields one attribute-code-keyed map per product, memory-bounded
 * (the implementation owns chunking + EntityManager::clear()). Lives in the
 * Export seam: the Export-core adapter ({@see \App\Export\Application\Feed\ExportBuilderFeedValues})
 * implements it over ExportBuilder; the Feed generator consumes it.
 */
interface FeedProductValues
{
    /**
     * @return iterable<array<string, string>> attribute code => serialized value, per product
     */
    public function forScope(FeedProductScope $scope): iterable;
}
