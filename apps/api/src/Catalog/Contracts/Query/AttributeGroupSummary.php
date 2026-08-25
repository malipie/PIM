<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Query;

use Symfony\Component\Uid\Uuid;

/** Lean cross-BC projection used to resolve user-facing group labels safely. */
final readonly class AttributeGroupSummary
{
    /** @param array<string, string> $label */
    public function __construct(
        public Uuid $id,
        public Uuid $tenantId,
        public string $code,
        public array $label,
    ) {
    }
}
