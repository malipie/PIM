<?php

declare(strict_types=1);

namespace App\Catalog\Application\PendingChanges;

use App\Catalog\Application\ValueWriteCore;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Repository\AttributeOptionRepositoryInterface;
use Throwable;

/**
 * Shared agent-facing value normalizer used by edits and object creation.
 * In addition to the canonical JSONB envelope it accepts an unambiguous,
 * localized select label and converts it to the stable option code.
 */
final readonly class AgentValueNormalizer
{
    public function __construct(
        private ValueWriteCore $core,
        private AttributeOptionRepositoryInterface $options,
    ) {
    }

    /** @return array{0: ?array<string, mixed>, 1: ?string} */
    public function normalise(Attribute $attribute, mixed $rawValue): array
    {
        try {
            $envelope = $this->core->normaliseForAttribute($attribute, $rawValue);
            $optionError = $this->resolveOptionLabels($attribute, $envelope);
            if (null !== $optionError) {
                return [null, $optionError];
            }
            $required = $this->core->requiredViolation($attribute, $envelope);
            if (null !== $required) {
                return [null, $required];
            }
            $violations = $this->core->formatViolations($attribute, $envelope);

            return [] === $violations ? [$envelope, null] : [null, $violations[0]];
        } catch (Throwable $failure) {
            return [null, 'Validation failed: '.$failure->getMessage()];
        }
    }

    /** @param array<string, mixed> $envelope */
    private function resolveOptionLabels(Attribute $attribute, array &$envelope): ?string
    {
        if (!\in_array($attribute->getType(), [AttributeType::Select, AttributeType::Multiselect], true)) {
            return null;
        }

        $allowedCodes = [];
        $labelsToCodes = [];
        foreach ($this->options->findByAttribute($attribute) as $option) {
            $allowedCodes[] = $option->getCode();
            foreach ($option->getLabel() as $label) {
                $key = self::lookupKey($label);
                if ('' !== $key) {
                    $labelsToCodes[$key][] = $option->getCode();
                }
            }
        }
        if ([] === $allowedCodes) {
            $configured = $attribute->getValidationRules()['option_codes'] ?? [];
            if (\is_array($configured)) {
                $allowedCodes = array_values(array_filter($configured, 'is_string'));
            }
        }
        if ([] === $allowedCodes) {
            return null;
        }

        $key = AttributeType::Select === $attribute->getType() ? 'option_code' : 'option_codes';
        $values = $envelope[$key] ?? null;
        $values = AttributeType::Select === $attribute->getType() ? [$values] : $values;
        if (!\is_array($values)) {
            return $this->unknownOptionReason($attribute, $allowedCodes);
        }

        $resolved = [];
        foreach ($values as $value) {
            if (!\is_string($value) || '' === trim($value)) {
                return $this->unknownOptionReason($attribute, $allowedCodes);
            }
            if (\in_array($value, $allowedCodes, true)) {
                $resolved[] = $value;
                continue;
            }

            $matches = array_values(array_unique($labelsToCodes[self::lookupKey($value)] ?? []));
            if (1 !== \count($matches)) {
                return $this->unknownOptionReason($attribute, $allowedCodes, $value);
            }
            $resolved[] = $matches[0];
        }

        $envelope[$key] = AttributeType::Select === $attribute->getType() ? $resolved[0] : $resolved;

        return null;
    }

    /** @param list<string> $allowedCodes */
    private function unknownOptionReason(Attribute $attribute, array $allowedCodes, ?string $given = null): string
    {
        $prefix = null === $given
            ? \sprintf('Unknown option for attribute "%s".', $attribute->getCode())
            : \sprintf('Option "%s" does not exist or is ambiguous for attribute "%s".', $given, $attribute->getCode());

        return $prefix.' Available option codes: '.implode(', ', $allowedCodes).'.';
    }

    private static function lookupKey(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }
}
