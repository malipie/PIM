<?php

declare(strict_types=1);

namespace App\Export\Catalog\Domain\Mapping;

/**
 * One slot mapping (ADR-0023 §6.4, CPDF-P2-02): which source feeds a catalog
 * template slot. Source kinds: `attribute` (a PIM attribute code), `static` (a
 * fixed value), `template` (interpolation of other fields). Transforms are a
 * deferred hook (plan §7), out of MVP scope — so there is no `transform` field.
 */
final class CatalogFieldMapping
{
    public function __construct(
        public readonly string $slot,
        public readonly ?string $sourceKind,
        public readonly ?string $sourceRef,
        public readonly ?string $sourceValue,
    ) {
    }

    /**
     * @param array<mixed, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $slot = \is_string($data['slot'] ?? null) ? $data['slot'] : '';

        $source = \is_array($data['source'] ?? null) ? $data['source'] : [];
        $kind = \is_string($source['kind'] ?? null) ? $source['kind'] : null;
        $ref = \is_string($source['ref'] ?? null) ? $source['ref'] : null;
        $value = \is_string($source['value'] ?? null) ? $source['value'] : null;

        return new self($slot, $kind, $ref, $value);
    }

    /**
     * Parse a persisted `field_mappings` list.
     *
     * @param array<mixed, mixed> $raw
     *
     * @return list<self>
     */
    public static function listFromArray(array $raw): array
    {
        $out = [];
        foreach ($raw as $entry) {
            if (\is_array($entry)) {
                $out[] = self::fromArray($entry);
            }
        }

        return $out;
    }
}
