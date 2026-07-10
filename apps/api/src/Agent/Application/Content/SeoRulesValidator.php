<?php

declare(strict_types=1);

namespace App\Agent\Application\Content;

/**
 * AICG-P4-01 (#2337, ADR-0030 decision C) — SEO rules are CONTENT
 * validation parametrised by the recipe, not a data-model concern:
 * length budget, focus-keyword presence, and a keyword-stuffing
 * density ceiling. A violation is data for the caller (regenerate or
 * flag the proposal), never an exception.
 */
final readonly class SeoRulesValidator
{
    /**
     * Fraction of words the focus keyword may make up before the copy
     * reads as stuffing (industry rule of thumb ~2-3%; 8% is already
     * unnatural for the short meta formats this validates).
     */
    private const float STUFFING_DENSITY_CEILING = 0.08;
    private const int STUFFING_MIN_OCCURRENCES = 3;

    /**
     * @param array<string, mixed> $seoRules the recipe's constraints.seo
     *                                       slot: {keyword?, title_len?, meta_len?}
     * @param int|null             $maxLen   the applicable length budget picked
     *                                       by the caller (title vs meta)
     *
     * @return list<string> violations; [] = valid
     */
    public function validate(string $text, array $seoRules, ?int $maxLen = null): array
    {
        $violations = [];
        $length = mb_strlen(trim($text));

        if (\is_int($maxLen) && $length > $maxLen) {
            $violations[] = \sprintf('too_long: %d characters against the budget of %d', $length, $maxLen);
        }

        $keyword = $seoRules['keyword'] ?? null;
        if (\is_string($keyword) && '' !== trim($keyword)) {
            $keyword = trim($keyword);
            $occurrences = mb_substr_count(mb_strtolower($text), mb_strtolower($keyword));
            if (0 === $occurrences) {
                $violations[] = \sprintf('missing_keyword: "%s" does not appear in the copy', $keyword);
            } else {
                $keywordSplit = preg_split('/\\s+/', $keyword);
                $keywordWords = max(1, \count(false === $keywordSplit ? [] : $keywordSplit));
                $textSplit = preg_split('/\\s+/', trim($text));
                $totalWords = max(1, \count(false === $textSplit ? [] : $textSplit));
                $density = ($occurrences * $keywordWords) / $totalWords;
                if ($occurrences >= self::STUFFING_MIN_OCCURRENCES && $density > self::STUFFING_DENSITY_CEILING) {
                    $violations[] = \sprintf(
                        'keyword_stuffing: "%s" appears %d times (density %.0f%%, ceiling %.0f%%)',
                        $keyword,
                        $occurrences,
                        $density * 100,
                        self::STUFFING_DENSITY_CEILING * 100,
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * The applicable budget for a target attribute: title-ish targets
     * use title_len, everything else meta_len. Parametrised by the
     * recipe, never hard-coded (decision C).
     *
     * @param array<string, mixed> $seoRules
     */
    public function budgetFor(string $targetAttribute, array $seoRules): ?int
    {
        $isTitle = str_contains(mb_strtolower($targetAttribute), 'title');
        $budget = $isTitle ? ($seoRules['title_len'] ?? null) : ($seoRules['meta_len'] ?? null);

        return \is_int($budget) ? $budget : null;
    }
}
