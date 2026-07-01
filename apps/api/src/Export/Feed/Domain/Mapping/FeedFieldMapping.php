<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Mapping;

/**
 * One slot mapping (ADR-0023 §6.4, XMLF-P2-03): which source feeds a descriptor
 * slot and the optional transform. Source kinds: `attribute` (a PIM attribute
 * code), `static` (a fixed value), `template` (interpolation of other fields).
 */
final class FeedFieldMapping
{
    /**
     * @param array<mixed, mixed>|null $transform {kind, …params}
     */
    public function __construct(
        public readonly string $slot,
        public readonly ?string $sourceKind,
        public readonly ?string $sourceRef,
        public readonly ?string $sourceValue,
        public readonly ?array $transform,
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

        $transform = \is_array($data['transform'] ?? null) ? $data['transform'] : null;

        return new self($slot, $kind, $ref, $value, $transform);
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
