<?php

declare(strict_types=1);

namespace App\Export\Contracts;

/**
 * Seam over the product value source a catalog projects (ADR-0023 §6.3,
 * CPDF-P2-02). Yields one attribute-code-keyed map per product, memory-bounded
 * (the implementation owns chunking + EntityManager::clear()). Lives in the
 * Export seam: the Export-core adapter ({@see \App\Export\Application\Catalog\ExportBuilderCatalogValues})
 * implements it over ExportBuilder; the catalog generator consumes it.
 */
interface CatalogProductValues
{
    /**
     * @return iterable<array<string, string>> attribute code => serialized value, per product
     */
    public function forScope(CatalogProductScope $scope): iterable;
}
