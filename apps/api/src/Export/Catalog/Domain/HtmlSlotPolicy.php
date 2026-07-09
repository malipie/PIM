<?php

declare(strict_types=1);

namespace App\Export\Catalog\Domain;

/**
 * How a template slot treats an HTML/text product value before it reaches the
 * catalog HTML (ADR-0027, CPDF-P0-04). Chosen per slot in the field mapping —
 * never hard-coded — so a name/price/SKU is fully escaped while a description
 * keeps a safe rich-text subset.
 */
enum HtmlSlotPolicy: string
{
    /** Full HTML-escape — the default for scalar slots (name, sku, price). */
    case Escape = 'escape';

    /** Keep a conservative rich-text allow-list — for description slots. */
    case RichText = 'richtext';

    /** Remove every tag, keep the text content. */
    case Strip = 'strip';
}
