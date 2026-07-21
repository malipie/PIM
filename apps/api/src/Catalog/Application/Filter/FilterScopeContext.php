<?php

declare(strict_types=1);

namespace App\Catalog\Application\Filter;

/**
 * #2673 — resolved value-context of a filter document: the channel/locale
 * the operator picked in the advanced filter panel. Codes come from the
 * DSL root (`scope: {channel, locale}`); the channel id is resolved by
 * {@see FilterScopeResolver} so the SQL path can predicate on
 * `object_values.channel_id` without a join.
 */
final readonly class FilterScopeContext
{
    public function __construct(
        /** RFC 4122 string form, null = no channel dimension. */
        public ?string $channelId = null,
        public ?string $channelCode = null,
        /** Short locale code (`pl`), null = no locale dimension. */
        public ?string $locale = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return null === $this->channelId && null === $this->locale;
    }
}
