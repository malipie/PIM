<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Agent\Application\Content\ContentGrounding;
use App\Agent\Application\Content\ContentGroundingService;
use App\Agent\Application\Content\GroundingGate;
use App\Agent\Application\Content\SeoRulesValidator;
use App\Agent\Application\Llm\AgentLlmClientInterface;
use App\Agent\Application\Run\UsageCostCalculator;
use App\Agent\Domain\Entity\BrandVoiceProfile;
use App\Agent\Domain\Entity\ContentRecipe;
use App\Agent\Infrastructure\Anthropic\AgentModelSelector;
use App\Catalog\Contracts\Command\ContentValuePort;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * AICG-P4-02 (#2338, ADR-0030) — SEO copy (meta title / description /
 * SEO text) as the second variant of the same grounded mechanism
 * (plan §5 rule 3): the pipeline of generate_product_description plus
 * the recipe-parametrised SEO rules (P4-01, decision C — rules live in
 * the recipe, the target stays a plain text attribute).
 *
 * A violation triggers ONE regeneration with the violations spelled
 * out; a copy that still violates is materialized WITH the flags — the
 * operator sees them in the proposal and decides.
 */
final readonly class GenerateSeoTextTool implements AgentToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private ContentGroundingService $grounding,
        private GroundingGate $gate,
        private SeoRulesValidator $seoRules,
        private ContentValuePort $contentValues,
        private AgentLlmClientInterface $llm,
        private AgentModelSelector $models,
        private UsageCostCalculator $costs,
    ) {
    }

    public function name(): string
    {
        return 'generate_seo_text';
    }

    public function description(): string
    {
        return 'Generate SEO copy (meta title, meta description, SEO text) for a product STRICTLY from its own attribute values, enforcing the recipe\'s SEO rules: length budget, focus keyword presence, no keyword stuffing. The proposal lands in pending_changes for human approval. Use for meta_* / SEO attributes; use generate_product_description for regular descriptive copy.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_id' => [
                    'type' => 'string',
                    'description' => 'UUID of the product object.',
                ],
                'target_attribute' => [
                    'type' => 'string',
                    'description' => 'Code of the SEO text attribute to fill (e.g. "meta_description"). Defaults to the recipe\'s target.',
                ],
                'locale' => [
                    'type' => 'string',
                    'description' => 'Locale of the generated copy (short code). Omit for the global value.',
                ],
                'channel' => [
                    'type' => 'string',
                    'description' => 'Channel code when the copy targets one channel.',
                ],
                'recipe_id' => [
                    'type' => 'string',
                    'description' => 'ContentRecipe UUID. Omit to use the recipe configured for the target attribute.',
                ],
                'keyword' => [
                    'type' => 'string',
                    'description' => 'Focus keyword override for this request (defaults to the recipe\'s constraints.seo.keyword).',
                ],
                'pending_change_batch_id' => [
                    'type' => 'string',
                    'description' => 'Append to an existing proposal batch. Usually injected automatically.',
                ],
            ],
            'required' => ['product_id'],
        ];
    }

    public function requiredPermission(): string
    {
        return 'object.write';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function execute(array $arguments, AgentToolContext $context): array
    {
        $productId = $arguments['product_id'] ?? null;
        if (!\is_string($productId) || !Uuid::isValid($productId)) {
            return ['error' => 'product_id must be a valid UUID.'];
        }

        $recipe = $this->resolveRecipe($arguments);
        if (null === $recipe) {
            return ['error' => 'No content recipe found — pass recipe_id or configure one for the target attribute in Settings → AI content.'];
        }
        $targetAttribute = \is_string($arguments['target_attribute'] ?? null) && '' !== $arguments['target_attribute']
            ? $arguments['target_attribute']
            : $recipe->getTargetAttribute();
        $locale = \is_string($arguments['locale'] ?? null) && '' !== $arguments['locale'] ? $arguments['locale'] : null;
        $channel = \is_string($arguments['channel'] ?? null) && '' !== $arguments['channel'] ? $arguments['channel'] : null;

        $seo = $this->seoRulesOf($recipe);
        if (\is_string($arguments['keyword'] ?? null) && '' !== $arguments['keyword']) {
            $seo['keyword'] = $arguments['keyword'];
        }
        $budget = $this->seoRules->budgetFor($targetAttribute, $seo);

        $grounding = $this->grounding->ground(Uuid::fromString($productId), $recipe, $context->tenant, $locale, $channel);
        $verdict = $this->gate->evaluate($grounding, $recipe);
        if (!$verdict->isSufficient()) {
            return $verdict->toToolResult();
        }

        $voice = $this->resolveVoice($recipe);
        $model = $this->models->defaultModel();
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];

        $generated = $this->generate($context, $model, $grounding, $targetAttribute, $locale, $seo, $budget, $voice, null, $usage);
        if ('' === $generated) {
            return ['error' => 'The model returned no text — retry or adjust the recipe.'];
        }

        $violations = $this->seoRules->validate($generated, $seo, $budget);
        if ([] !== $violations) {
            // ONE regeneration with the violations spelled out (backlog:
            // "naruszenie -> jedna regeneracja lub flag").
            $regenerated = $this->generate($context, $model, $grounding, $targetAttribute, $locale, $seo, $budget, $voice, $violations, $usage);
            if ('' !== $regenerated) {
                $generated = $regenerated;
                $violations = $this->seoRules->validate($generated, $seo, $budget);
            }
        }

        $batchId = \is_string($arguments['pending_change_batch_id'] ?? null) && Uuid::isValid($arguments['pending_change_batch_id'])
            ? Uuid::fromString($arguments['pending_change_batch_id'])
            : Uuid::v7();
        $proposal = $this->contentValues->materializeGeneratedValue(
            $batchId,
            $context->userId,
            Uuid::fromString($productId),
            $targetAttribute,
            $generated,
            $locale,
            $channel,
            meta: [
                'intent' => 'generate_seo_text',
                'source_attributes' => $grounding->usedCodes,
                'recipe_id' => $recipe->getId()->toRfc4122(),
                'model' => $model,
            ],
        );

        $llmUsage = $usage + ['cost_usd' => $this->costs->costUsd($model, $usage['input_tokens'], $usage['output_tokens'], 0, 0)];

        if (!$proposal->isMaterialized()) {
            return $proposal->toToolResult() + ['llm_usage' => $llmUsage];
        }

        return [
            'status' => 'materialized',
            'pending_change_batch_id' => $batchId->toRfc4122(),
            'affected_count' => 1,
            'product_id' => $productId,
            'target_attribute' => $targetAttribute,
            'locale' => $proposal->scopeLocale,
            'channel' => $proposal->scopeChannel,
            'generated_preview' => mb_substr($generated, 0, 300),
            'source_attributes' => $grounding->usedCodes,
            'recipe' => $recipe->getCode(),
            'seo_violations' => $violations,
            'llm_usage' => $llmUsage,
            'note' => [] === $violations
                ? 'Proposal materialized for approval — nothing was written to the product.'
                : 'Proposal materialized WITH unresolved SEO violations — point them out to the user.',
        ];
    }

    /**
     * @param array<string, mixed>                         $seo
     * @param list<string>|null                            $violations
     * @param array{input_tokens: int, output_tokens: int} $usage
     */
    private function generate(
        AgentToolContext $context,
        string $model,
        ContentGrounding $grounding,
        string $targetAttribute,
        ?string $locale,
        array $seo,
        ?int $budget,
        ?BrandVoiceProfile $voice,
        ?array $violations,
        array &$usage,
    ): string {
        $response = $this->llm->create(
            $context->tenant,
            $model,
            $this->systemPrompt($seo, $budget, $voice),
            [[
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => $this->userPrompt($grounding, $targetAttribute, $locale, $violations)]],
            ]],
            tools: [],
            promptCaching: false,
        );
        $usage['input_tokens'] += $response->inputTokens;
        $usage['output_tokens'] += $response->outputTokens;

        $text = '';
        foreach ($response->contentBlocks as $block) {
            if (($block['type'] ?? null) === 'text' && \is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        return trim($text);
    }

    /**
     * @param array<string, mixed> $seo
     */
    private function systemPrompt(array $seo, ?int $budget, ?BrandVoiceProfile $voice): string
    {
        $lines = [
            'You are an SEO copywriter inside a PIM. You write short SEO copy from structured product data.',
            '',
            'ANTI-HALLUCINATION CONTRACT (hard rules):',
            '- Use ONLY the facts provided in the user message. They are the complete ground truth.',
            '- NEVER state a parameter, number, material, feature or property that is absent from the facts.',
            '- Do not speculate, average, or "typically" anything. Missing means unmentioned.',
            '',
            'Output: plain text only, one line, no markup, no quotes, no preamble.',
        ];
        if (\is_int($budget)) {
            $lines[] = \sprintf('HARD length budget: at most %d characters.', $budget);
        }
        $keyword = $seo['keyword'] ?? null;
        if (\is_string($keyword) && '' !== $keyword) {
            $lines[] = \sprintf('The focus keyword "%s" must appear exactly once, naturally. Never repeat it — keyword stuffing is forbidden.', $keyword);
        }
        if (null !== $voice) {
            $lines[] = \sprintf('Brand voice "%s": %s', $voice->getName(), $voice->getTone());
            if ([] !== $voice->getBannedWords()) {
                $lines[] = '- Never use the words: '.implode(', ', $voice->getBannedWords()).'.';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string>|null $violations
     */
    private function userPrompt(ContentGrounding $grounding, string $targetAttribute, ?string $locale, ?array $violations): string
    {
        $facts = json_encode($grounding->facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $retry = null === $violations || [] === $violations
            ? ''
            : "\n\nYour previous attempt violated these SEO rules — fix ALL of them:\n- ".implode("\n- ", $violations);

        return \sprintf(
            "Write the \"%s\" SEO attribute%s for this product.\n\nProduct facts (the ONLY permissible source of claims):\n%s%s",
            $targetAttribute,
            null !== $locale ? \sprintf(' in locale "%s"', $locale) : '',
            \is_string($facts) ? $facts : '{}',
            $retry,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function resolveRecipe(array $arguments): ?ContentRecipe
    {
        $recipeId = $arguments['recipe_id'] ?? null;
        if (\is_string($recipeId) && Uuid::isValid($recipeId)) {
            return $this->em->find(ContentRecipe::class, $recipeId);
        }

        $target = $arguments['target_attribute'] ?? null;
        $criteria = \is_string($target) && '' !== $target ? ['targetAttribute' => $target] : ['code' => 'meta_seo'];
        $repository = $this->em->getRepository(ContentRecipe::class);

        return $repository->findOneBy($criteria + ['isBuiltIn' => true]) ?? $repository->findOneBy($criteria);
    }

    private function resolveVoice(ContentRecipe $recipe): ?BrandVoiceProfile
    {
        if (null !== $recipe->getBrandVoiceId()) {
            $pinned = $this->em->find(BrandVoiceProfile::class, $recipe->getBrandVoiceId()->toRfc4122());
            if ($pinned instanceof BrandVoiceProfile) {
                return $pinned;
            }
        }

        return $this->em->getRepository(BrandVoiceProfile::class)->findOneBy(['isDefault' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function seoRulesOf(ContentRecipe $recipe): array
    {
        $seo = $recipe->getConstraints()['seo'] ?? [];
        if (!\is_array($seo)) {
            return [];
        }

        $rules = [];
        foreach ($seo as $key => $value) {
            if (\is_string($key)) {
                $rules[$key] = $value;
            }
        }

        return $rules;
    }
}
