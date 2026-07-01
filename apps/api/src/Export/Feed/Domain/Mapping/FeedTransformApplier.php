<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Mapping;

use DateTimeImmutable;

/**
 * Applies the closed list of feed transforms (ADR-0023 §6.4, XMLF-P2-03) over a
 * value already serialized to a string by the export ValueSerializer. Deliberate
 * departure from the APIC 1:1 rule — feeds need `price` "123.45 PLN",
 * `availability` enums, etc. Anything beyond this list is a hook (§7).
 *
 * Transforms: default/fallback, price, number, date, enum_map, template/concat,
 * strip_html, truncate.
 */
final class FeedTransformApplier
{
    /**
     * @param array<mixed, mixed>   $transform {kind, …params}
     * @param array<string, string> $context   feed tokens (currency) + sibling field values (template)
     */
    public function apply(array $transform, string $value, array $context = []): string
    {
        $kind = \is_string($transform['kind'] ?? null) ? $transform['kind'] : 'none';

        return match ($kind) {
            'default', 'fallback' => '' === $value ? $this->stringParam($transform, 'value') : $value,
            'price' => $this->price($value, $context),
            'number' => $this->number($value, $transform),
            'date' => $this->date($value, $transform),
            'enum_map' => $this->enumMap($value, $transform),
            'template', 'concat' => $this->interpolate($this->stringParam($transform, 'value'), $context, $value),
            'strip_html' => trim(strip_tags($value)),
            'truncate' => $this->truncate($value, $transform),
            default => $value,
        };
    }

    /**
     * @param array<string, string> $context
     */
    private function price(string $value, array $context): string
    {
        $amount = $this->toFloat($value);
        if (null === $amount) {
            return $value;
        }
        $currency = \is_string($context['currency'] ?? null) ? $context['currency'] : '';

        return trim(sprintf('%.2f %s', $amount, $currency));
    }

    /**
     * @param array<mixed, mixed> $transform
     */
    private function number(string $value, array $transform): string
    {
        $amount = $this->toFloat($value);
        if (null === $amount) {
            return $value;
        }
        $precision = \is_int($transform['precision'] ?? null) ? $transform['precision'] : 2;

        return number_format($amount, max(0, $precision), '.', '');
    }

    /**
     * @param array<mixed, mixed> $transform
     */
    private function date(string $value, array $transform): string
    {
        if ('' === $value) {
            return '';
        }
        $ts = strtotime($value);
        if (false === $ts) {
            return $value;
        }
        $format = \is_string($transform['format'] ?? null) ? $transform['format'] : DateTimeImmutable::ATOM;

        return date($format, $ts);
    }

    /**
     * enum_map: a `rule` shortcut (stock qty → in/out of stock) or an explicit
     * `map` of value => output with an optional `default`.
     *
     * @param array<mixed, mixed> $transform
     */
    private function enumMap(string $value, array $transform): string
    {
        $rule = \is_string($transform['rule'] ?? null) ? $transform['rule'] : null;
        if (null !== $rule) {
            $qty = $this->toFloat($value);

            return match ($rule) {
                'stock' => (null !== $qty && $qty > 0) ? 'in_stock' : 'out_of_stock',
                'meta_stock' => (null !== $qty && $qty > 0) ? 'in stock' : 'out of stock',
                'ceneo_avail' => (null !== $qty && $qty > 0) ? '1' : '0',
                default => $value,
            };
        }

        $map = \is_array($transform['map'] ?? null) ? $transform['map'] : [];
        if (\array_key_exists($value, $map) && \is_scalar($map[$value])) {
            return (string) $map[$value];
        }

        $default = $this->stringParam($transform, 'default');

        return '' !== $default ? $default : $value;
    }

    /**
     * @param array<mixed, mixed> $transform
     */
    private function truncate(string $value, array $transform): string
    {
        $to = \is_int($transform['to'] ?? null) ? $transform['to'] : 0;
        if ($to <= 0 || mb_strlen($value) <= $to) {
            return $value;
        }
        $cut = mb_substr($value, 0, $to);
        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim(false !== $lastSpace && $lastSpace > 0 ? mb_substr($cut, 0, $lastSpace) : $cut);
    }

    /**
     * @param array<string, string> $context
     */
    private function interpolate(string $template, array $context, string $self): string
    {
        $ctx = $context + ['value' => $self, 'self' => $self];

        return preg_replace_callback(
            '/\{([a-z0-9_.]+)\}/i',
            static fn (array $m): string => $ctx[$m[1]] ?? $m[0],
            $template,
        ) ?? $template;
    }

    /**
     * @param array<mixed, mixed> $transform
     */
    private function stringParam(array $transform, string $key): string
    {
        return \is_string($transform[$key] ?? null) ? $transform[$key] : '';
    }

    private function toFloat(string $value): ?float
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim($value));
        if ('' === $normalized || !is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }
}
