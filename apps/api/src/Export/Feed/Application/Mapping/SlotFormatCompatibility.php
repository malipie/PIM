<?php

declare(strict_types=1);

namespace App\Export\Feed\Application\Mapping;

use App\Export\Feed\Domain\Descriptor\SlotFormat;

/**
 * Soft type-compatibility hints for the mapper (ADR-0023 §6.4, XMLF-P3-01).
 *
 * When an operator maps a PIM attribute onto a descriptor slot, the slot's
 * declared {@see SlotFormat} implies a value shape (a price node wants a
 * numeric attribute, a date node wants a date attribute…). This helper returns
 * a human-readable warning when the attribute type is unlikely to satisfy the
 * slot format. It is advisory only — the mapper never blocks the mapping, it
 * paints a badge so the operator can override deliberately.
 *
 * Attribute type vocabulary mirrors {@see \App\Catalog\Contracts\AttributeType};
 * we key on the string tag carried by the cross-BC
 * {@see \App\Catalog\Contracts\Query\AttributeSummary} to avoid coupling the
 * Feed sub-area to the Catalog domain enum.
 */
final class SlotFormatCompatibility
{
    /**
     * Attribute type tags that satisfy each slot format. `text` accepts any
     * scalar attribute (everything renders to a string), so it is never warned.
     *
     * @var array<string, list<string>>
     */
    private const array COMPATIBLE = [
        'html' => ['wysiwyg', 'textarea', 'text'],
        'url' => ['text', 'textarea', 'identifier', 'asset', 'reference', 'relation', 'email'],
        'price' => ['price', 'number', 'metric'],
        'number' => ['number', 'metric', 'price', 'identifier'],
        'date' => ['date', 'datetime'],
        'enum' => ['select', 'multiselect', 'boolean', 'text'],
        'category' => ['reference', 'relation', 'select', 'text', 'identifier'],
    ];

    /**
     * @return string|null a warning message, or null when the pairing is fine
     */
    public function warn(SlotFormat $format, string $attributeType): ?string
    {
        // Text/free-form slots accept any attribute — no warning.
        $allowed = self::COMPATIBLE[$format->value] ?? null;
        if (null === $allowed) {
            return null;
        }

        if (\in_array($attributeType, $allowed, true)) {
            return null;
        }

        return sprintf(
            'Atrybut typu "%s" może nie pasować do slotu w formacie "%s".',
            $attributeType,
            $format->value,
        );
    }
}
