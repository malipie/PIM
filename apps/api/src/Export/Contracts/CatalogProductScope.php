<?php

declare(strict_types=1);

namespace App\Export\Contracts;

use Symfony\Component\Uid\Uuid;

/**
 * The scope a catalog projects, as primitives (ADR-0023 §6.6, CPDF-P2-02). Lives
 * in the Export seam so the Export-core value adapter never depends on the
 * Catalog sub-area: the catalog generator builds it from a template + object
 * type and hands it to {@see CatalogProductValues}.
 */
final class CatalogProductScope
{
    /**
     * @param list<string>             $attributeCodes attribute codes the mappings read
     * @param array<mixed, mixed>|null $filter         filter-DSL snapshot (null = whole catalog)
     */
    public function __construct(
        public readonly Uuid $objectTypeId,
        public readonly array $attributeCodes,
        public readonly ?string $locale = null,
        public readonly ?string $channel = null,
        public readonly ?array $filter = null,
    ) {
    }
}
