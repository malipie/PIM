<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\CrossFieldRulesValidator;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Repository\ObjectValueRepositoryInterface;
use App\Catalog\Domain\Rule\CompareRule;
use App\Catalog\Domain\Rule\CrossFieldRules;
use App\Catalog\Domain\Rule\VisibleWhenRuleEvaluator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * DP-07 (#2037, ADR-0025) — the semantics matrix for cross-field evaluation.
 * The view passed to evaluate() is `code => NON-EMPTY global envelope`.
 */
final class CrossFieldRulesValidatorTest extends TestCase
{
    private CrossFieldRulesValidator $validator;

    protected function setUp(): void
    {
        $values = $this->createStub(ObjectValueRepositoryInterface::class);
        $this->validator = new CrossFieldRulesValidator($values, new VisibleWhenRuleEvaluator());
    }

    /**
     * @return list<\App\Catalog\Domain\Rule\CrossFieldRule>
     */
    private function compare(string $op): array
    {
        return CrossFieldRules::fromArray([
            ['type' => 'compare', 'left' => 'weight_net', 'op' => $op, 'right' => 'weight_gross'],
        ]);
    }

    /**
     * @return list<\App\Catalog\Domain\Rule\CrossFieldRule>
     */
    private function requireWhen(mixed $conditionValue = true): array
    {
        return CrossFieldRules::fromArray([
            ['type' => 'require_when', 'if' => ['field' => 'expandable_storage', 'operator' => 'equals', 'value' => $conditionValue], 'then' => ['required' => 'max_sd_card_gb']],
        ]);
    }

    #[Test]
    public function compareViolatesWhenLeftExceedsRight(): void
    {
        $violations = $this->validator->evaluate($this->compare('lte'), [
            'weight_net' => ['value' => 12.5],
            'weight_gross' => ['value' => 10],
        ]);

        self::assertCount(1, $violations);
        self::assertSame('weight_net', $violations[0]->attributeCode);
        self::assertSame(CompareRule::TYPE, $violations[0]->ruleType);
        self::assertStringContainsString('less than or equal to', $violations[0]->message);
    }

    #[Test]
    public function compareHoldsWhenSatisfied(): void
    {
        self::assertSame([], $this->validator->evaluate($this->compare('lte'), [
            'weight_net' => ['value' => 9],
            'weight_gross' => ['value' => 10],
        ]));
    }

    #[Test]
    public function compareSkipsWhenEitherSideMissingOrNonNumeric(): void
    {
        self::assertSame([], $this->validator->evaluate($this->compare('lte'), [
            'weight_net' => ['value' => 12.5],
        ]));
        self::assertSame([], $this->validator->evaluate($this->compare('lte'), [
            'weight_net' => ['value' => 'heavy'],
            'weight_gross' => ['value' => 10],
        ]));
        self::assertSame([], $this->validator->evaluate($this->compare('lte'), []));
    }

    #[Test]
    public function compareReadsPriceAmountAndCastsNumericStrings(): void
    {
        $violations = $this->validator->evaluate($this->compare('lt'), [
            'weight_net' => ['amount' => '15.5'],
            'weight_gross' => ['value' => 10],
        ]);

        self::assertCount(1, $violations);
    }

    #[Test]
    public function eqDoesNotSplitOnIntVsFloat(): void
    {
        self::assertSame([], $this->validator->evaluate($this->compare('eq'), [
            'weight_net' => ['value' => 5],
            'weight_gross' => ['value' => 5.0],
        ]));
    }

    #[Test]
    public function everyOperatorEvaluates(): void
    {
        $view = static fn (int|float $l, int|float $r): array => [
            'weight_net' => ['value' => $l],
            'weight_gross' => ['value' => $r],
        ];

        self::assertCount(1, $this->validator->evaluate($this->compare('lt'), $view(10, 10)));
        self::assertSame([], $this->validator->evaluate($this->compare('lt'), $view(9, 10)));
        self::assertCount(1, $this->validator->evaluate($this->compare('gt'), $view(10, 10)));
        self::assertSame([], $this->validator->evaluate($this->compare('gte'), $view(10, 10)));
        self::assertCount(1, $this->validator->evaluate($this->compare('neq'), $view(10, 10)));
        self::assertSame([], $this->validator->evaluate($this->compare('neq'), $view(9, 10)));
    }

    #[Test]
    public function requireWhenFiresOnConditionTrueAndMissingTarget(): void
    {
        $violations = $this->validator->evaluate($this->requireWhen(), [
            'expandable_storage' => ['value' => true],
        ]);

        self::assertCount(1, $violations);
        self::assertSame('max_sd_card_gb', $violations[0]->attributeCode);
        self::assertStringContainsString('required when', $violations[0]->message);
    }

    #[Test]
    public function requireWhenHoldsWhenTargetPresentOrConditionFalse(): void
    {
        self::assertSame([], $this->validator->evaluate($this->requireWhen(), [
            'expandable_storage' => ['value' => true],
            'max_sd_card_gb' => ['value' => 512],
        ]));
        self::assertSame([], $this->validator->evaluate($this->requireWhen(), [
            'expandable_storage' => ['value' => false],
        ]));
        // Missing condition field => condition false (visible_when semantics).
        self::assertSame([], $this->validator->evaluate($this->requireWhen(), []));
    }

    #[Test]
    public function requireWhenMatchesSelectEnvelopeShape(): void
    {
        $rules = CrossFieldRules::fromArray([
            ['type' => 'require_when', 'if' => ['field' => 'light_type', 'operator' => 'equals', 'value' => 'bulb'], 'then' => ['required' => 'socket_type']],
        ]);

        $violations = $this->validator->evaluate($rules, [
            'light_type' => ['option_code' => 'bulb'],
        ]);

        self::assertCount(1, $violations);
    }

    #[Test]
    public function overlayAppliesGlobalWritesAndClearsRemoveCodes(): void
    {
        $net = new Attribute('weight_net', ['en' => 'Net'], AttributeType::Number);
        $gross = new Attribute('weight_gross', ['en' => 'Gross'], AttributeType::Number);

        $base = [
            'weight_net' => ['value' => 5],
            'weight_gross' => ['value' => 10],
        ];

        $view = $this->validator->overlay($base, [
            // Global write overrides.
            ['attribute' => $net, 'envelope' => ['value' => 12], 'locale' => null, 'channelId' => null],
            // Clearing removes the code — "cleared" == "never set".
            ['attribute' => $gross, 'envelope' => ['value' => null], 'locale' => null, 'channelId' => null],
        ]);

        self::assertSame(['value' => 12], $view['weight_net']);
        self::assertArrayNotHasKey('weight_gross', $view);
    }

    #[Test]
    public function overlayIgnoresLocaleAndChannelRoutedWrites(): void
    {
        $net = new Attribute('weight_net', ['en' => 'Net'], AttributeType::Number);

        $view = $this->validator->overlay([], [
            ['attribute' => $net, 'envelope' => ['value' => 12], 'locale' => 'de', 'channelId' => null],
            ['attribute' => $net, 'envelope' => ['value' => 13], 'locale' => null, 'channelId' => Uuid::v7()],
        ]);

        self::assertSame([], $view);
    }
}
