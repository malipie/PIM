<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Query;

/**
 * #2550 — a scoped, denormalised object row for the live profile preview.
 *
 * Primitive-only projection (no Catalog Domain types) so a consumer BC
 * (ApiConfigurator) can render "what the integrator sees" without crossing
 * into Catalog\Domain. `attributes` is the object's denormalised
 * `attributes_indexed` map (attribute code => value); the caller prunes it to
 * the profile's allow-list.
 */
final readonly class ObjectSample
{
    /**
     * @param array<string, mixed> $attributes attribute code => indexed value
     */
    public function __construct(
        public string $id,
        public string $code,
        public string $kind,
        public array $attributes,
    ) {
    }
}
