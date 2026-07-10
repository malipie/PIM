<?php

declare(strict_types=1);

namespace App\Agent\Application\Run;

use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Domain\Entity\BrandVoiceProfile;
use App\Agent\Domain\Entity\ContentRecipe;
use Doctrine\ORM\EntityManagerInterface;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * AGENT-P1-03 (#1955) — deterministic system prompt of the loop
 * (PRD 5.2): ground plans in read tools, ask when ambiguous, write
 * only through approval-gated tools, never fabricate numbers.
 *
 * AICG-P2-03 (#2333, ADR-0030) — content-generation runs (recipe_id /
 * brand_voice_id in the view context) additionally get the "how to
 * write" sections: the recipe's output contract, the tenant's brand
 * voice, and the hard anti-hallucination contract. Runs without those
 * keys produce a byte-identical prompt.
 */
final readonly class AgentSystemPromptBuilder
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function build(AgentRun $run): string
    {
        $context = $run->getContext();
        $contextJson = [] === $context
            ? 'none'
            : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $scopeRule = $this->scopeRule($context);
        $contentSections = $this->contentSections($context);

        return <<<PROMPT
            You are the catalog agent of a PIM system, acting strictly within the permissions of the initiating user.

            Rules:
            - Ground every plan in real numbers obtained from the read tools; never estimate or fabricate counts.
            - When the intent is ambiguous, ask ONE precise clarifying question as plain text and stop.
            - Catalog writes happen ONLY through the provided write tools, which materialize proposals for human approval; you never commit changes yourself.
            - A "forbidden" tool result means the action is outside the user's permissions - do not retry it; tell the user instead.
            - HIGH-LEVEL intents (e.g. "prepare the DE launch for category X") are multi-step plans: break them into a sequence of tool calls in ONE run - ground first, then materialize each step. Later write-tool calls automatically append to the same proposal batch, so the operator approves ONE collective diff; keep the steps within the run's tool-call budget.
            - When you are done (proposal materialized or question asked), reply with a short plain-text summary in the user's language: for multi-step plans list each step with its numbers.{$scopeRule}

            View context of the initiating user (carry it into tool calls instead of asking for it):
            {$contextJson}{$contentSections}
            PROMPT;
    }

    /**
     * #2153 — when the operator has rows SELECTED in the list, the agent
     * must act on that selection, not the whole view, and must ask before
     * widening the scope. Returns an extra rule line (leading newline +
     * indent to sit in the bullet list) or '' when there is no selection.
     *
     * @param array<string, mixed> $context
     */
    private function scopeRule(array $context): string
    {
        $selected = $context['selected_ids'] ?? null;
        if (!\is_array($selected)) {
            return '';
        }
        $count = \count(array_filter($selected, static fn (mixed $id): bool => \is_string($id) && '' !== $id));
        if (0 === $count) {
            return '';
        }

        $total = $context['total_matching'] ?? null;
        $totalPhrase = \is_int($total) && $total > $count
            ? \sprintf('all %d in the active view', $total)
            : 'the whole list';

        return "\n            - SELECTION SCOPE: the operator has {$count} object(s) SELECTED in the list. The write tools default to this selection when you omit object_ids and filter_dsl - use that default. Only target {$totalPhrase} if the intent CLEARLY says so (e.g. \"all\", \"every\", \"the whole list\"). If it is not clear whether they mean the {$count} selected or {$totalPhrase}, ask ONE clarifying question first and stop.";
    }

    /**
     * AICG-P2-03 (#2333) — the "how to write" block of content runs.
     * Both ids resolve tenant-scoped (TenantFilter); an unknown id keeps
     * the prompt baseline — the tool rejects the unknown recipe earlier
     * with its own error. A recipe-attached voice is used when the
     * context does not name one explicitly.
     *
     * @param array<string, mixed> $context
     */
    private function contentSections(array $context): string
    {
        $recipe = $this->loadRecipe($context['recipe_id'] ?? null);
        $voice = $this->loadVoice($context['brand_voice_id'] ?? null);
        if (null === $voice && null !== $recipe && null !== $recipe->getBrandVoiceId()) {
            $voice = $this->loadVoice($recipe->getBrandVoiceId()->toRfc4122());
        }

        if (null === $recipe && null === $voice) {
            return '';
        }

        $sections = '';
        if (null !== $recipe) {
            $sections .= $this->recipeSection($recipe);
        }
        if (null !== $voice) {
            $sections .= $this->voiceSection($voice);
        }

        return $sections."\n\n            ANTI-HALLUCINATION CONTRACT for content generation:"
            ."\n            - Use ONLY the facts provided in the grounding/tool results of this run."
            ."\n            - NEVER state a parameter, number, feature or property that is absent from the provided facts."
            ."\n            - When the facts are insufficient, say exactly what is missing instead of inventing it.";
    }

    private function loadRecipe(mixed $id): ?ContentRecipe
    {
        if (!\is_string($id) || '' === $id) {
            return null;
        }

        return $this->em->find(ContentRecipe::class, $id);
    }

    private function loadVoice(mixed $id): ?BrandVoiceProfile
    {
        if (!\is_string($id) || '' === $id) {
            return null;
        }

        return $this->em->find(BrandVoiceProfile::class, $id);
    }

    private function recipeSection(ContentRecipe $recipe): string
    {
        $constraints = $recipe->getConstraints();
        $format = \is_string($constraints['format'] ?? null) ? $constraints['format'] : 'plain';
        $maxLen = $constraints['max_len'] ?? null;
        $lines = [
            \sprintf('Content recipe "%s":', $recipe->getName()),
            \sprintf('- Target attribute: %s', $recipe->getTargetAttribute()),
            \sprintf('- Output format: %s%s', $format, \is_int($maxLen) ? \sprintf('; max length %d characters', $maxLen) : ''),
        ];

        $seo = $constraints['seo'] ?? null;
        if (\is_array($seo) && [] !== $seo) {
            $rules = [];
            if (\is_string($seo['keyword'] ?? null) && '' !== $seo['keyword']) {
                $rules[] = \sprintf('focus keyword "%s" must appear naturally', $seo['keyword']);
            }
            if (\is_int($seo['title_len'] ?? null)) {
                $rules[] = \sprintf('title at most %d characters', $seo['title_len']);
            }
            if (\is_int($seo['meta_len'] ?? null)) {
                $rules[] = \sprintf('meta description at most %d characters', $seo['meta_len']);
            }
            if ([] !== $rules) {
                $lines[] = '- SEO rules: '.implode('; ', $rules).'. No keyword stuffing.';
            }
        }

        $toneHint = $recipe->getToneHint();
        if (null !== $toneHint && '' !== $toneHint) {
            $lines[] = \sprintf('- Tone hint: %s', $toneHint);
        }

        return "\n\n            ".implode("\n            ", $lines);
    }

    private function voiceSection(BrandVoiceProfile $voice): string
    {
        $lines = [
            \sprintf('Brand voice "%s":', $voice->getName()),
            \sprintf('- Tone: %s', $voice->getTone()),
        ];

        if ([] !== $voice->getGlossary()) {
            $terms = array_map(
                static fn (array $entry): string => \sprintf('"%s" -> "%s"', $entry['term'], $entry['use']),
                $voice->getGlossary(),
            );
            $lines[] = '- Imposed glossary (always use the right-hand phrasing): '.implode('; ', $terms);
        }
        if ([] !== $voice->getBannedWords()) {
            $lines[] = '- Banned words (never use): '.implode(', ', $voice->getBannedWords());
        }
        $example = $voice->getExamples()[0] ?? null;
        if (null !== $example) {
            $lines[] = \sprintf('- GOOD copy example: "%s"', $example['good']);
            $lines[] = \sprintf('- BAD copy example (never write like this): "%s"', $example['bad']);
        }

        return "\n\n            ".implode("\n            ", $lines);
    }
}
