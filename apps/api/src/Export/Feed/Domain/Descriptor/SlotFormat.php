<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Descriptor;

/**
 * Expected value format of a slot (ADR-0023 §6.3/§6.5, XMLF-P2-01). Drives
 * per-product validation (XMLF-P2-04) and the mapper type-mismatch hints
 * (XMLF-P3-01). Mirrors the `fmt` values in the design's TEMPLATE_SLOTS.
 */
enum SlotFormat: string
{
    case Text = 'text';
    case Html = 'html';
    case Url = 'url';
    case Price = 'price';
    case Number = 'number';
    case Date = 'date';
    case Enum = 'enum';
    case Category = 'category';
}
