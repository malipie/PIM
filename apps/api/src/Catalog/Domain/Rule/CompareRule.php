<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Rule;

use InvalidArgumentException;

/**
 * DP-07 (#2037, ADR-0025) — `weight_net <= weight_gross`-style numeric
 * comparison of two attributes. Both sides must be numeric attributes of
 * the SAME AttributeType (guarded on rule save, not here — the VO only
 * owns the shape). Evaluation skips when either side is missing or
 * non-numeric: required-ness is a separate dimension.
 */
final readonly class CompareRule implements CrossFieldRule
{
    public const string TYPE = 'compare';

    public const array OPERATORS = ['lt', 'lte', 'gt', 'gte', 'eq', 'neq'];

    public function __construct(
        public string $left,
        public string $op,
        public string $right,
    ) {
        if ('' === trim($this->left) || '' === trim($this->right)) {
            throw new InvalidArgumentException('compare.left and compare.right must be non-empty attribute codes.');
        }
        if ($this->left === $this->right) {
            throw new InvalidArgumentException('compare.left and compare.right must be different attributes.');
        }
        if (!\in_array($this->op, self::OPERATORS, true)) {
            throw new InvalidArgumentException(\sprintf(
                'Unsupported compare.op "%s" — supported: %s.',
                $this->op,
                implode(', ', self::OPERATORS),
            ));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $left = $payload['left'] ?? null;
        $op = $payload['op'] ?? null;
        $right = $payload['right'] ?? null;
        if (!\is_string($left) || !\is_string($op) || !\is_string($right)) {
            throw new InvalidArgumentException('compare rule requires string `left`, `op` and `right`.');
        }

        return new self($left, $op, $right);
    }

    public function referencedCodes(): array
    {
        return [$this->left, $this->right];
    }

    public function toArray(): array
    {
        return ['type' => self::TYPE, 'left' => $this->left, 'op' => $this->op, 'right' => $this->right];
    }
}
