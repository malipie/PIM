<?php

declare(strict_types=1);

namespace App\Agent\Application\Cost;

use App\Agent\Application\Run\UsageCostCalculator;
use App\Agent\Domain\Entity\ContentRecipe;
use App\Agent\Infrastructure\Anthropic\AgentModelSelector;

/**
 * AICG-P6-03 (#2346, ADR-0030) — estimates the token/USD footprint of a
 * bulk content run (N products × one recipe) up front, so the modal can
 * preview it and the day-cap gate can refuse before spending (plan §8
 * R3). Deliberately deterministic (no model call): a conservative
 * over-estimate is the right bias for a spend guard.
 *
 * The per-product baseline is the live-measured envelope of the content
 * tools (AICG-P3-02/P4-02 dev-stack smokes: ~1.2k input / ~0.4k output
 * for a grounded round-trip); the recipe refines it — more source
 * attributes widen the facts payload, the length budget bounds output.
 */
final readonly class ContentCostEstimator
{
    private const int BASE_INPUT_TOKENS = 1_200;
    private const int PER_SOURCE_INPUT_TOKENS = 60;
    private const int FALLBACK_OUTPUT_TOKENS = 400;
    private const int DEFAULT_SOURCE_COUNT = 4;
    private const int MIN_OUTPUT_TOKENS = 120;
    private const int MAX_OUTPUT_TOKENS = 2_000;

    public function __construct(
        private AgentModelSelector $models,
        private UsageCostCalculator $costs,
    ) {
    }

    public function estimate(int $productCount, ?ContentRecipe $recipe): ContentCostEstimate
    {
        $count = max(0, $productCount);
        $model = $this->models->defaultModel();

        $sourceCount = null !== $recipe ? \count($recipe->getSourceAttributes()) : self::DEFAULT_SOURCE_COUNT;
        $inputPerProduct = self::BASE_INPUT_TOKENS + $sourceCount * self::PER_SOURCE_INPUT_TOKENS;
        $outputPerProduct = $this->outputTokensForRecipe($recipe);

        $estInput = $inputPerProduct * $count;
        $estOutput = $outputPerProduct * $count;
        $cost = $this->costs->costUsd($model, $estInput, $estOutput);

        return new ContentCostEstimate(
            productCount: $count,
            inputTokensPerProduct: $inputPerProduct,
            outputTokensPerProduct: $outputPerProduct,
            estInputTokens: $estInput,
            estOutputTokens: $estOutput,
            estCostUsd: $cost,
            model: $model,
        );
    }

    private function outputTokensForRecipe(?ContentRecipe $recipe): int
    {
        $maxLen = $recipe?->getConstraints()['max_len'] ?? null;
        if (!\is_int($maxLen)) {
            return self::FALLBACK_OUTPUT_TOKENS;
        }

        // ~4 characters per token, +20% headroom for markup/whitespace.
        $estimate = (int) round($maxLen / 4 * 1.2);

        return max(self::MIN_OUTPUT_TOKENS, min(self::MAX_OUTPUT_TOKENS, $estimate));
    }
}
