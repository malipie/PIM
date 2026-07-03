<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Repository\ObjectValueRepositoryInterface;
use App\Catalog\Domain\Rule\CompareRule;
use App\Catalog\Domain\Rule\CrossFieldRule;
use App\Catalog\Domain\Rule\CrossFieldRules;
use App\Catalog\Domain\Rule\CrossFieldViolation;
use App\Catalog\Domain\Rule\RequireWhenRule;
use App\Catalog\Domain\Rule\VisibleWhenRuleEvaluator;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;

/**
 * DP-07 (#2037, ADR-0025) — evaluates ObjectType-level cross-field rules
 * (`compare`, `require_when`) against the object's effective GLOBAL view:
 * existing global-scope ObjectValue rows overlaid with the incoming writes.
 *
 * Deliberately NOT built on `attributes_indexed`: the rebuild listener
 * skips bulk flows (BulkContext) and a freshly created object has an empty
 * cache mid-flush — the canonical rows are the only source that is always
 * fresh on both write paths. Evaluation is GLOBAL scope only (locale=null,
 * channel=null), consistent with `attributes_indexed` and `visible_when`;
 * locale/channel-routed writes are not overlaid.
 *
 * Contract (ADR-0025 semantics table):
 *   - an empty incoming envelope REMOVES the code from the view
 *     ("cleared" == "never set"),
 *   - compare skips when either side is missing or non-numeric,
 *   - require_when fires when the condition holds and the target is absent.
 */
final class CrossFieldRulesValidator
{
    /** @var array<string, list<CrossFieldRule>> parsed-rule memo keyed by "otId|schemaVersion" */
    private array $ruleMemo = [];

    public function __construct(
        private readonly ObjectValueRepositoryInterface $values,
        private readonly VisibleWhenRuleEvaluator $visibleWhen,
    ) {
    }

    /**
     * Parsed rules for the ObjectType, [] when none (or when stored JSONB
     * is malformed — runtime is lenient, the PATCH edge is strict).
     *
     * @return list<CrossFieldRule>
     */
    public function rulesFor(ObjectType $objectType): array
    {
        $raw = $objectType->getValidationRules();
        if ([] === $raw) {
            return [];
        }

        $key = $objectType->getId()->toRfc4122().'|'.$objectType->getSchemaVersion();
        if (!isset($this->ruleMemo[$key])) {
            try {
                $this->ruleMemo[$key] = CrossFieldRules::fromArray($raw);
            } catch (InvalidArgumentException) {
                // Stale/hand-edited JSONB must not brick every write.
                $this->ruleMemo[$key] = [];
            }
        }

        return $this->ruleMemo[$key];
    }

    /**
     * Admin-path entry point: one query for the existing GLOBAL rows (only
     * when the ObjectType has rules), overlay the prepared writes, evaluate.
     *
     * @param list<array{attribute: Attribute, envelope: array<string, mixed>, locale: ?string, channelId: ?Uuid}> $incoming
     *
     * @return list<CrossFieldViolation>
     */
    public function validateForUpsert(CatalogObject $object, array $incoming): array
    {
        $rules = $this->rulesFor($object->getObjectType());
        if ([] === $rules) {
            return [];
        }

        $view = [];
        foreach ($this->values->findByObject($object) as $row) {
            if (null === $row->getLocale() && null === $row->getChannelId()
                && !ValueWriteCore::isEmptyEnvelope($row->getValue())) {
                $view[$row->getAttribute()->getCode()] = $row->getValue();
            }
        }

        return $this->evaluate($rules, $this->overlay($view, $incoming));
    }

    /**
     * Apply global-routed incoming writes onto a base view. An empty
     * envelope unsets the code — clearing behaves like never-set.
     *
     * @param array<string, array<string, mixed>>                                                                  $view
     * @param list<array{attribute: Attribute, envelope: array<string, mixed>, locale: ?string, channelId: ?Uuid}> $incoming
     *
     * @return array<string, array<string, mixed>>
     */
    public function overlay(array $view, array $incoming): array
    {
        foreach ($incoming as $write) {
            if (null !== $write['locale'] || null !== $write['channelId']) {
                continue;
            }
            $code = $write['attribute']->getCode();
            if (ValueWriteCore::isEmptyEnvelope($write['envelope'])) {
                unset($view[$code]);

                continue;
            }
            $view[$code] = $write['envelope'];
        }

        return $view;
    }

    /**
     * @param list<CrossFieldRule>                $rules
     * @param array<string, array<string, mixed>> $view  code => NON-EMPTY global envelope
     *
     * @return list<CrossFieldViolation>
     */
    public function evaluate(array $rules, array $view): array
    {
        $violations = [];
        foreach ($rules as $rule) {
            $violation = match (true) {
                $rule instanceof CompareRule => $this->evaluateCompare($rule, $view),
                $rule instanceof RequireWhenRule => $this->evaluateRequireWhen($rule, $view),
                default => null,
            };
            if (null !== $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * @param array<string, array<string, mixed>> $view
     */
    private function evaluateCompare(CompareRule $rule, array $view): ?CrossFieldViolation
    {
        $left = $this->numericValue($view[$rule->left] ?? null);
        $right = $this->numericValue($view[$rule->right] ?? null);
        if (null === $left || null === $right) {
            // Missing / non-numeric side => skip; required-ness is a
            // separate dimension (ADR-0025 semantics).
            return null;
        }

        // Float-normalise both sides so eq/neq do not split on int-vs-float
        // representation of the same number (5 vs 5.0).
        $left = (float) $left;
        $right = (float) $right;

        $holds = match ($rule->op) {
            'lt' => $left < $right,
            'lte' => $left <= $right,
            'gt' => $left > $right,
            'gte' => $left >= $right,
            'eq' => $left === $right,
            'neq' => $left !== $right,
            default => true,
        };
        if ($holds) {
            return null;
        }

        return new CrossFieldViolation(
            attributeCode: $rule->left,
            ruleType: CompareRule::TYPE,
            message: \sprintf(
                'Attribute "%s" must be %s attribute "%s" (%s vs %s).',
                $rule->left,
                self::OP_LABELS[$rule->op],
                $rule->right,
                var_export($left, true),
                var_export($right, true),
            ),
            referencedCodes: $rule->referencedCodes(),
        );
    }

    private const array OP_LABELS = [
        'lt' => 'less than',
        'lte' => 'less than or equal to',
        'gt' => 'greater than',
        'gte' => 'greater than or equal to',
        'eq' => 'equal to',
        'neq' => 'different from',
    ];

    /**
     * @param array<string, array<string, mixed>> $view
     */
    private function evaluateRequireWhen(RequireWhenRule $rule, array $view): ?CrossFieldViolation
    {
        // The view carries envelope shapes exactly like attributes_indexed,
        // so the visible_when evaluator's scalar extraction applies as-is.
        if (!$this->visibleWhen->isVisible($rule->condition, $view)) {
            return null;
        }
        if (isset($view[$rule->requiredCode])) {
            return null;
        }

        return new CrossFieldViolation(
            attributeCode: $rule->requiredCode,
            ruleType: RequireWhenRule::TYPE,
            message: \sprintf(
                'Attribute "%s" is required when "%s" equals %s.',
                $rule->requiredCode,
                $rule->condition->field,
                json_encode($rule->condition->value, JSON_THROW_ON_ERROR),
            ),
            referencedCodes: $rule->referencedCodes(),
        );
    }

    /**
     * Numeric extraction per envelope shape: number/metric carry `value`,
     * price carries `amount`. Numeric strings cast to float; anything else
     * yields null (=> compare skip).
     */
    private function numericValue(mixed $envelope): int|float|null
    {
        if (!\is_array($envelope)) {
            return null;
        }
        $raw = $envelope['value'] ?? $envelope['amount'] ?? null;
        if (\is_int($raw) || \is_float($raw)) {
            return $raw;
        }
        if (\is_string($raw) && is_numeric($raw)) {
            return (float) $raw;
        }

        return null;
    }
}
