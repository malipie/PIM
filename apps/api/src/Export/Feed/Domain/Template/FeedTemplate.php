<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Template;

use App\Export\Feed\Domain\Enum\FeedTemplateKind;

/**
 * A built-in feed template (ADR-0023 §6.5, XMLF-P2-02): the declarative
 * descriptor plus its default field mappings, cloned into a FeedProfile when a
 * user creates a feed from the template (XMLF-P2-06). Held in code (not a DB
 * table) so predefs are versioned with the app.
 */
final class FeedTemplate
{
    /**
     * @param array<string, mixed>       $descriptor      canonical descriptor (validated by FeedDescriptor)
     * @param list<array<string, mixed>> $defaultMappings list of {slot, source, transform?}
     */
    public function __construct(
        public readonly FeedTemplateKind $kind,
        public readonly array $descriptor,
        public readonly array $defaultMappings,
    ) {
    }
}
