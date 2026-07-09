<?php

declare(strict_types=1);

namespace App\Export\Catalog\Domain\Mapping;

/**
 * Builds the template item map (slot target => value) from a product's raw
 * attribute values (ADR-0023 §6.4, CPDF-P2-02). For each mapping it resolves the
 * source (attribute / static / template). Transforms are a deferred hook (plan
 * §7), out of MVP scope — the mapper is pure and dependency-free. The catalog
 * generator feeds the result into the template slots.
 */
final class CatalogItemMapper
{
    /**
     * @param list<CatalogFieldMapping> $mappings
     * @param array<string, string>     $attributes raw attribute values keyed by attribute code
     *
     * @return array<string, string> slot target => resolved value
     */
    public function map(array $mappings, array $attributes): array
    {
        $item = [];
        foreach ($mappings as $mapping) {
            $item[$mapping->slot] = $this->resolveSource($mapping, $attributes);
        }

        return $item;
    }

    /**
     * @param array<string, string> $attributes
     */
    private function resolveSource(CatalogFieldMapping $mapping, array $attributes): string
    {
        return match ($mapping->sourceKind) {
            'attribute' => $attributes[$mapping->sourceRef ?? ''] ?? '',
            'static' => $mapping->sourceValue ?? '',
            'template' => $this->interpolate($mapping->sourceValue ?? '', $attributes),
            default => '',
        };
    }

    /**
     * @param array<string, string> $vars
     */
    private function interpolate(string $template, array $vars): string
    {
        return preg_replace_callback(
            '/\{([a-z0-9_.]+)\}/i',
            static fn (array $m): string => $vars[$m[1]] ?? $m[0],
            $template,
        ) ?? $template;
    }
}
