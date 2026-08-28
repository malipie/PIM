<?php

declare(strict_types=1);

namespace App\Catalog\Application\Validation;

use App\Catalog\Domain\Entity\Attribute;

/**
 * Contract for per-{@see \App\Catalog\Contracts\AttributeType} validators
 * dispatched by {@see AttributeValueValidator}.
 *
 * Implementations check the raw JSONB `value` payload + apply the rules
 * the admin configured on `Attribute.validation_rules` (e.g. `min`/`max`
 * for `number`, `max_length`/`pattern` for `text`, `unit_family` for
 * `metric`). Returning an empty list means "value is acceptable".
 */
interface AttributeValueValidatorInterface
{
    /**
     * @param array<string, mixed> $value
     *
     * @return list<ValidationError>
     */
    public function validate(Attribute $attribute, array $value): array;
}
