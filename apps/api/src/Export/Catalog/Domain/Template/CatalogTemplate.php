<?php

declare(strict_types=1);

namespace App\Export\Catalog\Domain\Template;

use App\Export\Catalog\Domain\Enum\CatalogTemplateKind;

/**
 * A built-in catalog PDF template (ADR-0027, CPDF-P2-01): the Twig archetype
 * plus the named slots it exposes and the default slot->attribute bindings
 * cloned into a CatalogProfile when a user creates a catalog from the template.
 * Held in code (not a DB table) so predefs are versioned with the app, mirroring
 * {@see \App\Export\Feed\Domain\Template\FeedTemplate}.
 */
final class CatalogTemplate
{
    /**
     * @param list<array{slot: string, label: string, format: string}> $slots           the named slots the template exposes
     * @param list<array{slot: string, source: string}>                $defaultMappings default slot->attribute-code bindings
     */
    public function __construct(
        public readonly CatalogTemplateKind $kind,
        public readonly string $twig,
        public readonly array $slots,
        public readonly array $defaultMappings,
    ) {
    }

    /**
     * @return list<string>
     */
    public function slotNames(): array
    {
        return array_map(static fn (array $slot): string => $slot['slot'], $this->slots);
    }
}
