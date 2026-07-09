<?php

declare(strict_types=1);

namespace App\Export\Catalog\Domain\Enum;

/**
 * PDF catalog template kind (ADR-0027, CPDF-P1-01). Each kind drives a
 * predefined layout: `sheet` renders one product per page as a data sheet,
 * `grid` a multi-product grid, `pricelist` a compact price list.
 */
enum CatalogTemplateKind: string
{
    case Sheet = 'sheet';
    case Grid = 'grid';
    case Pricelist = 'pricelist';
}
