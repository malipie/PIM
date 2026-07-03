<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Rule;

/**
 * DP-07 (#2037) — one broken cross-field rule, transport-agnostic
 * (the admin path maps to 422, the import path to a `cross_field` issue).
 */
final readonly class CrossFieldViolation
{
    public function __construct(
        public string $attributeCode,
        public string $ruleType,
        public string $message,
        /** @var list<string> every attribute code the broken rule reads */
        public array $referencedCodes = [],
    ) {
    }
}
