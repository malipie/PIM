<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Rule;

use InvalidArgumentException;

/**
 * DP-07 (#2037, ADR-0025) — strict parser for the ObjectType-level
 * `validation_rules` JSONB list. PATCH is the only writer and the column
 * defaults to `[]`, so parsing is strict everywhere: any malformed entry
 * throws at the domain edge and the JSONB never carries garbage
 * (same philosophy as VisibleWhenRule).
 */
final class CrossFieldRules
{
    /**
     * @param array<int|string, mixed> $raw
     *
     * @return list<CrossFieldRule>
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $raw): array
    {
        if (!array_is_list($raw)) {
            throw new InvalidArgumentException('validation_rules must be a JSON list of rule objects.');
        }

        $rules = [];
        foreach ($raw as $index => $entry) {
            if (!\is_array($entry)) {
                throw new InvalidArgumentException(\sprintf('validation_rules[%d] must be an object.', $index));
            }
            /** @var array<string, mixed> $entry */
            $type = $entry['type'] ?? null;
            $rules[] = match ($type) {
                CompareRule::TYPE => CompareRule::fromArray($entry),
                RequireWhenRule::TYPE => RequireWhenRule::fromArray($entry),
                default => throw new InvalidArgumentException(\sprintf(
                    'validation_rules[%d].type must be one of: %s.',
                    $index,
                    implode(', ', [CompareRule::TYPE, RequireWhenRule::TYPE]),
                )),
            };
        }

        return $rules;
    }

    /**
     * @param list<CrossFieldRule> $rules
     *
     * @return list<array<string, mixed>>
     */
    public static function toStoredArray(array $rules): array
    {
        return array_map(static fn (CrossFieldRule $rule): array => $rule->toArray(), $rules);
    }
}
