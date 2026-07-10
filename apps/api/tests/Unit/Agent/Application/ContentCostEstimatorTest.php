<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Application;

use App\Agent\Application\Cost\ContentCostEstimator;
use App\Agent\Application\Run\UsageCostCalculator;
use App\Agent\Domain\Entity\ContentRecipe;
use App\Agent\Infrastructure\Anthropic\AgentModelSelector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AICG-P6-03 (#2346) — the pre-flight estimate is deterministic and
 * scales linearly with the product count, so the day-cap gate and the
 * modal preview can trust it before any tokens are spent.
 */
final class ContentCostEstimatorTest extends TestCase
{
    #[Test]
    public function estimateScalesLinearlyWithTheProductCount(): void
    {
        $estimator = $this->estimator();
        $recipe = $this->recipe(sources: ['material', 'color'], maxLen: 800);

        $one = $estimator->estimate(1, $recipe);
        $fifty = $estimator->estimate(50, $recipe);

        self::assertSame(50, $fifty->productCount);
        self::assertSame($one->inputTokensPerProduct * 50, $fifty->estInputTokens);
        self::assertSame($one->outputTokensPerProduct * 50, $fifty->estOutputTokens);
        // 2 sources -> 1200 + 2*60 input; 800 chars/4*1.2 = 240 output.
        self::assertSame(1_320, $one->inputTokensPerProduct);
        self::assertSame(240, $one->outputTokensPerProduct);
    }

    #[Test]
    public function theLengthBudgetBoundsTheOutputEstimate(): void
    {
        $estimator = $this->estimator();

        $noRecipe = $estimator->estimate(1, null);
        self::assertSame(400, $noRecipe->outputTokensPerProduct, 'no recipe falls back to the measured average');

        $huge = $estimator->estimate(1, $this->recipe(sources: [], maxLen: 40_000));
        self::assertSame(2_000, $huge->outputTokensPerProduct, 'output is capped so a silly budget cannot explode the estimate');

        $tiny = $estimator->estimate(1, $this->recipe(sources: [], maxLen: 10));
        self::assertSame(120, $tiny->outputTokensPerProduct, 'and floored so a tiny budget still bills the base call');
    }

    #[Test]
    public function zeroProductsCostNothing(): void
    {
        $estimate = $this->estimator()->estimate(0, $this->recipe(sources: ['x'], maxLen: 500));

        self::assertSame(0, $estimate->productCount);
        self::assertSame(0, $estimate->estInputTokens);
        self::assertSame('0.000000', $estimate->estCostUsd);
    }

    #[Test]
    public function costUsesTheDefaultTierRates(): void
    {
        // 1200+60 input, 240 output per product at $3/$15 per MTok.
        $estimate = $this->estimator()->estimate(10, $this->recipe(sources: ['material'], maxLen: 800));

        $expectedInput = (1_200 + 60) * 10;
        $expectedOutput = 240 * 10;
        $expectedCost = ($expectedInput * 3.0 + $expectedOutput * 15.0) / 1_000_000;
        self::assertSame(number_format($expectedCost, 6, '.', ''), $estimate->estCostUsd);
    }

    private function estimator(): ContentCostEstimator
    {
        $models = new AgentModelSelector('claude-sonnet-test', 'claude-opus-test');

        return new ContentCostEstimator($models, new UsageCostCalculator($models, 3.0, 15.0, 5.0, 25.0));
    }

    /**
     * @param list<string> $sources
     */
    private function recipe(array $sources, int $maxLen): ContentRecipe
    {
        return new ContentRecipe(
            code: 'product_description',
            name: 'Opis',
            targetAttribute: 'description',
            sourceAttributes: $sources,
            constraints: ['format' => 'plain', 'max_len' => $maxLen],
        );
    }
}
