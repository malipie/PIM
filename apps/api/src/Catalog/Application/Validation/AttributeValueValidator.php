<?php

declare(strict_types=1);

namespace App\Catalog\Application\Validation;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Repository\AttributeOptionRepositoryInterface;

/**
 * Per-AttributeType validator dispatcher.
 *
 * The dispatcher reads {@see Attribute::getType()} + the JSONB
 * `validation_rules` payload and runs the matching {@see AttributeValueValidatorInterface}.
 * Returning a flat list of {@see ValidationError} keeps the contract
 * agnostic of Symfony Validator vs. RFC 7807 vs. admin form rendering —
 * the API layer (#41) and the admin UI (#56) translate them to whichever
 * shape they need.
 *
 * Validators operate on the raw JSONB `value` array (the `ObjectValue.value`
 * field), not on the entity itself, so this validator works equally well
 * for: API request payloads (validate before persist), CLI imports
 * (validate before bulk insert), and agent operations (validate before
 * approval). The shape per type is:
 *
 *   text/number/date/boolean: `{value: scalar}`
 *   select:                   `{option_code: 'red'}`
 *   multiselect:              `{option_codes: ['red', 'blue']}`
 *   asset:                    `{asset_id: '<uuid>'}`
 *   relation:                 `{object_id: '<uuid>'}`
 *   price:                    `{amount: 19.99, currency: 'PLN'}`
 *   metric:                   `{value: 12.5, unit: 'kg'}`
 */
final readonly class AttributeValueValidator
{
    /**
     * @param array<string, AttributeValueValidatorInterface> $validators map of `AttributeType->value` to validator
     */
    public function __construct(
        private array $validators,
    ) {
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return list<ValidationError>
     */
    public function validate(Attribute $attribute, array $value): array
    {
        $type = $attribute->getType();
        $validator = $this->validators[$type->value] ?? null;
        if (null === $validator) {
            return [new ValidationError(
                path: 'value',
                code: 'attribute.unsupported_type',
                message: \sprintf('No validator registered for AttributeType "%s".', $type->value),
            )];
        }

        return $validator->validate($attribute, $value);
    }

    public static function default(?AttributeOptionRepositoryInterface $optionRepository = null): self
    {
        return new self([
            AttributeType::Text->value => new TypeValidator\TextValidator(),
            AttributeType::Number->value => new TypeValidator\NumberValidator(),
            AttributeType::Select->value => new TypeValidator\SelectValidator($optionRepository),
            AttributeType::Multiselect->value => new TypeValidator\MultiselectValidator($optionRepository),
            AttributeType::Date->value => new TypeValidator\DateValidator(),
            AttributeType::Boolean->value => new TypeValidator\BooleanValidator(),
            AttributeType::Asset->value => new TypeValidator\AssetValidator(),
            AttributeType::Relation->value => new TypeValidator\RelationValidator(),
            AttributeType::Price->value => new TypeValidator\PriceValidator(),
            AttributeType::Metric->value => new TypeValidator\MetricValidator(),
            AttributeType::Wysiwyg->value => new TypeValidator\WysiwygValidator(),
            AttributeType::Datetime->value => new TypeValidator\DatetimeValidator(),
            AttributeType::Textarea->value => new TypeValidator\TextareaValidator(),
            AttributeType::Color->value => new TypeValidator\ColorValidator(),
            AttributeType::Email->value => new TypeValidator\EmailValidator(),
            AttributeType::Identifier->value => new TypeValidator\IdentifierValidator(),
        ]);
    }
}
