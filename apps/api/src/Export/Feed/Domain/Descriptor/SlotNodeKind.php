<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Descriptor;

/**
 * How a slot renders into XML (ADR-0023 §6.3, XMLF-P2-01):
 *   - Element: `<name>value</name>`;
 *   - Attribute: `name="value"` on a parent element (Ceneo `<o price="…">`);
 *   - Repeatable: many sibling elements (a gallery → many `<i>`);
 *   - Keyvalue: PIM attributes as a `<a name="…">value</a>` list (Ceneo `<attrs>`).
 */
enum SlotNodeKind: string
{
    case Element = 'element';
    case Attribute = 'attribute';
    case Repeatable = 'repeatable';
    case Keyvalue = 'keyvalue';
}
