<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Domain\Rule\CompareRule;
use App\Catalog\Domain\Rule\CrossFieldRules;
use App\Catalog\Domain\Rule\RequireWhenRule;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DP-07 (#2037, ADR-0025) — strict parsing of the ObjectType-level
 * validation_rules JSONB list.
 */
final class CrossFieldRulesTest extends TestCase
{
    #[Test]
    public function parsesCompareAndRequireWhenAndRoundTrips(): void
    {
        $stored = [
            ['type' => 'compare', 'left' => 'weight_net', 'op' => 'lte', 'right' => 'weight_gross'],
            ['type' => 'require_when', 'if' => ['field' => 'expandable_storage', 'operator' => 'equals', 'value' => true], 'then' => ['required' => 'max_sd_card_gb']],
        ];

        $rules = CrossFieldRules::fromArray($stored);

        self::assertCount(2, $rules);
        self::assertInstanceOf(CompareRule::class, $rules[0]);
        self::assertInstanceOf(RequireWhenRule::class, $rules[1]);
        self::assertSame(['weight_net', 'weight_gross'], $rules[0]->referencedCodes());
        self::assertSame(['expandable_storage', 'max_sd_card_gb'], $rules[1]->referencedCodes());
        self::assertSame($stored, CrossFieldRules::toStoredArray($rules));
    }

    #[Test]
    public function rejectsUnknownRuleType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/type must be one of/');
        CrossFieldRules::fromArray([['type' => 'sum', 'left' => 'a', 'right' => 'b']]);
    }

    #[Test]
    public function rejectsNonListPayload(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CrossFieldRules::fromArray(['type' => 'compare']);
    }

    #[Test]
    public function compareRejectsUnknownOperator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/compare\.op/');
        CompareRule::fromArray(['left' => 'a', 'op' => 'between', 'right' => 'b']);
    }

    #[Test]
    public function compareRejectsSelfComparison(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CompareRule::fromArray(['left' => 'a', 'op' => 'lte', 'right' => 'a']);
    }

    #[Test]
    public function requireWhenRejectsMissingThenRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/then\.required/');
        RequireWhenRule::fromArray(['if' => ['field' => 'a', 'operator' => 'equals', 'value' => 1], 'then' => []]);
    }

    #[Test]
    public function requireWhenRejectsBadConditionShape(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RequireWhenRule::fromArray(['if' => ['field' => 'a', 'operator' => 'in', 'value' => [1]], 'then' => ['required' => 'b']]);
    }

    #[Test]
    public function requireWhenRejectsSelfTarget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RequireWhenRule::fromArray(['if' => ['field' => 'a', 'operator' => 'equals', 'value' => 1], 'then' => ['required' => 'a']]);
    }
}
