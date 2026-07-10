<?php

declare(strict_types=1);

namespace App\Agent\Application\Content;

use App\Agent\Domain\Entity\ContentRecipe;

/**
 * AICG-P2-02 (#2332, SEC, ADR-0030) — "generate only when there is
 * something to generate FROM" (plan §3a pkt 5, risk R2). Decides
 * whether a grounding carries enough facts for generation:
 *
 *   - `constraints.grounding.required` — codes that must ALL resolve
 *     (a spec sheet without dimensions is not a spec sheet),
 *   - `constraints.grounding.min_facts` — minimum resolved fact count,
 *     default 1: one hard fact beats an empty prompt, zero facts is
 *     pure invention.
 *
 * Insufficient grounding is a structured verdict, never an exception —
 * the tool returns it as tool_result and the model relays what is
 * missing instead of hallucinating it.
 */
final readonly class GroundingGate
{
    private const int DEFAULT_MIN_FACTS = 1;

    public function evaluate(ContentGrounding $grounding, ContentRecipe $recipe): GroundingVerdict
    {
        $rules = $this->groundingRules($recipe);
        $presentFacts = \count($grounding->facts);

        $minFacts = $rules['min_facts'] ?? self::DEFAULT_MIN_FACTS;
        if (\is_int($minFacts) && $presentFacts < $minFacts) {
            return GroundingVerdict::insufficient($grounding->missingCodes, $presentFacts);
        }

        $required = $rules['required'] ?? [];
        if (\is_array($required)) {
            foreach ($required as $code) {
                if (\is_string($code) && !\array_key_exists($code, $grounding->facts)) {
                    return GroundingVerdict::insufficient($grounding->missingCodes, $presentFacts);
                }
            }
        }

        return GroundingVerdict::sufficient($grounding->missingCodes, $presentFacts);
    }

    /**
     * @return array<mixed>
     */
    private function groundingRules(ContentRecipe $recipe): array
    {
        $slot = $recipe->getConstraints()['grounding'] ?? [];

        return \is_array($slot) ? $slot : [];
    }
}
