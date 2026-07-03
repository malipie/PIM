<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Rule;

use InvalidArgumentException;

/**
 * DP-07 (#2037, ADR-0025) — conditional required: `max_sd_card_gb` is
 * required when `expandable_storage equals true`. The condition reuses
 * {@see VisibleWhenRule} so the whole system speaks one condition shape
 * (conditional visibility on attribute_group_attributes uses the same VO);
 * Faza 1 operator extensions inherit automatically.
 */
final readonly class RequireWhenRule implements CrossFieldRule
{
    public const string TYPE = 'require_when';

    public function __construct(
        public VisibleWhenRule $condition,
        public string $requiredCode,
    ) {
        if ('' === trim($this->requiredCode)) {
            throw new InvalidArgumentException('require_when.then.required must be a non-empty attribute code.');
        }
        if ($this->requiredCode === $this->condition->field) {
            throw new InvalidArgumentException('require_when target must differ from the condition field.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $if = $payload['if'] ?? null;
        if (!\is_array($if)) {
            throw new InvalidArgumentException('require_when rule requires an `if` condition object.');
        }
        /** @var array<string, mixed> $if */
        $then = $payload['then'] ?? null;
        $required = \is_array($then) ? ($then['required'] ?? null) : null;
        if (!\is_string($required)) {
            throw new InvalidArgumentException('require_when rule requires `then.required` (attribute code).');
        }

        return new self(VisibleWhenRule::fromArray($if), $required);
    }

    public function referencedCodes(): array
    {
        return [$this->condition->field, $this->requiredCode];
    }

    public function toArray(): array
    {
        return [
            'type' => self::TYPE,
            'if' => $this->condition->toArray(),
            'then' => ['required' => $this->requiredCode],
        ];
    }
}
