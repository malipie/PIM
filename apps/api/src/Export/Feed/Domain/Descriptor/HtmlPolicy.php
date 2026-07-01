<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Descriptor;

/**
 * How HTML in a slot's value is handled when serialized (ADR-0023 §6.9,
 * XMLF-P2-01). Never raw — the writer escapes, wraps in CDATA, or strips tags.
 */
enum HtmlPolicy: string
{
    case Escape = 'escape';
    case Cdata = 'cdata';
    case Strip = 'strip';
}
