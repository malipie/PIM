<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Mapping;

/**
 * Builds the descriptor item map (slot target => value) from a product's raw
 * attribute values (ADR-0023 §6.4, XMLF-P2-03). For each mapping it resolves the
 * source (attribute / static / template) then applies the optional transform.
 * The generator (XMLF-P2-04) feeds the result to {@see \App\Export\Feed\Infrastructure\Writer\XmlFeedWriter}.
 */
final class FeedItemMapper
{
    public function __construct(private readonly FeedTransformApplier $transforms)
    {
    }

    /**
     * @param list<FeedFieldMapping> $mappings
     * @param array<string, string>  $attributes raw attribute values keyed by attribute code
     * @param array<string, string>  $context    feed tokens (currency, store_url, …)
     *
     * @return array<string, string> slot target => rendered value
     */
    public function map(array $mappings, array $attributes, array $context = []): array
    {
        $item = [];
        foreach ($mappings as $mapping) {
            $raw = $this->resolveSource($mapping, $attributes, $context);
            $item[$mapping->slot] = null !== $mapping->transform
                ? $this->transforms->apply($mapping->transform, $raw, $attributes + $context)
                : $raw;
        }

        return $item;
    }

    /**
     * @param array<string, string> $attributes
     * @param array<string, string> $context
     */
    private function resolveSource(FeedFieldMapping $mapping, array $attributes, array $context): string
    {
        return match ($mapping->sourceKind) {
            'attribute' => $attributes[$mapping->sourceRef ?? ''] ?? '',
            'static' => $mapping->sourceValue ?? '',
            'template' => $this->interpolate($mapping->sourceValue ?? '', $attributes + $context),
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
