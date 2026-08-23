<?php

declare(strict_types=1);

namespace App\Catalog\Application\Bulk;

use App\Catalog\Application\ValueWriteCore;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Shared\Application\TenantContext;

/**
 * Canonicalises a raw bulk-edit value into the same JSONB envelope the
 * single-edit / import write path produces, BEFORE it is written into the
 * denormalised `attributes_indexed` cache (#2664).
 *
 * Bulk handlers historically poked the cache with the raw scalar (`price = "1"`
 * instead of `{"amount":1,"currency":"PLN"}`). The product list tolerates the
 * bare value, but the typed detail-form input cannot parse it and renders
 * empty — a list/detail split. Reusing {@see ValueWriteCore::normalise()} keeps
 * bulk in lockstep with {@see \App\Catalog\Application\ObjectAttributesUpserter}
 * so every path emits the identical envelope shape per attribute type.
 *
 * NOTE: this fixes the cache SHAPE only. Bulk still writes the cache directly
 * (no `object_values` row) — the durable canonical write is the deferred
 * VIEW-13 refactor tracked separately.
 */
final readonly class BulkValueCanonicalizer
{
    public function __construct(
        private AttributeRepositoryInterface $attributes,
        private ValueWriteCore $core,
        private TenantContext $tenantContext,
    ) {
    }

    /**
     * Returns the canonical cache envelope for `$rawValue` on `$attrCode`.
     * Unknown attributes (and any no-active-tenant edge) fall back to the raw
     * value unchanged, preserving the pre-#2664 behaviour.
     */
    public function canonicalize(string $attrCode, mixed $rawValue): mixed
    {
        if ('' === $attrCode || null === $rawValue) {
            return $rawValue;
        }

        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            return $rawValue;
        }

        $attribute = $this->attributes->findByCode($attrCode, $tenant);
        if (!$attribute instanceof Attribute) {
            return $rawValue;
        }

        $envelope = $this->core->normaliseForAttribute($attribute, $rawValue);

        // A single-key `{value: …}` (text/number/date/boolean) mirrors the
        // canonical cache slot; the typed shapes (price/select/multiselect)
        // already carry their own keys.
        return $envelope;
    }
}
