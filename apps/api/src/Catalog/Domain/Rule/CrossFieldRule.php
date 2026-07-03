<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Rule;

/**
 * DP-07 (#2037, ADR-0025) — one ObjectType-level cross-field rule.
 * Implementations: {@see CompareRule}, {@see RequireWhenRule}.
 */
interface CrossFieldRule
{
    /**
     * Attribute codes this rule reads — used to pre-load existing values
     * (import prime) and to validate references on rule save.
     *
     * @return list<string>
     */
    public function referencedCodes(): array;

    /**
     * @return array<string, mixed> canonical stored JSONB shape
     */
    public function toArray(): array;
}
