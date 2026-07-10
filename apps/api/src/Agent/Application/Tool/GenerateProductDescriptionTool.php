<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Agent\Application\Content\ContentGrounding;
use App\Agent\Application\Content\ContentGroundingService;
use App\Agent\Application\Content\GroundingGate;
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
 * AICG-P3-02 (#2335, ADR-0030) — the flagship content tool: grounded
 * product-description generation, end to end inside ONE tool call:
 *
 *   grounding (facts from the product's own attributes, P2-01)
 *   -> completeness gate (insufficient_grounding instead of invention,
 *      P2-02)
 *   -> a nested LLM call on the default (Sonnet-tier) model with the
 *      recipe + brand voice + the hard anti-hallucination contract
 *   -> length/format check against the recipe
 *   -> materialization into pending_changes (P3-01) — NOTHING is
 *      applied by the tool itself; the operator approves the diff.
 *
 * The first "agent-native" tool (ADR-0030 decision 2): its engine is
 * the LLM, so grounding + prompt + generation live HERE; reads and
 * writes still go exclusively through `*\Contracts` ports.
 *
 * The nested call's usage is returned as `llm_usage` in the
 * tool_result; the loop adds it to the run's counters so the §8.5
 * budgets see every generated token (and the audit keeps a per-call
 * copy in agent_tool_calls.result_summary).
 */
final readonly class GenerateProductDescriptionTool implements AgentToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private ContentGroundingService $grounding,
        private GroundingGate $gate,
        private ContentValuePort $contentValues,
        private AgentLlmClientInterface $llm,
        private AgentModelSelector $models,
        private UsageCostCalculator $costs,
    ) {
    }

    public function name(): string
    {
        return 'generate_product_description';
    }

    public function description(): string
    {
        return 'Generate a product description (or other text attribute) STRICTLY from the product\'s own attribute values, following a content recipe and the tenant\'s brand voice. The proposal lands in pending_changes for human approval — nothing is written to the product by this call. Use when the user asks to write, improve or fill in descriptive copy for a specific product. Requires enough source facts: with too little data it returns insufficient_grounding and the list of attributes to fill first.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_id' => [
                    'type' => 'string',
                    'description' => 'UUID of the product object to describe.',
                ],
                'target_attribute' => [
                    'type' => 'string',
                    'description' => 'Code of the text attribute to fill (e.g. "description"). Defaults to the recipe\'s target.',
                ],
                'locale' => [
                    'type' => 'string',
                    'description' => 'Locale of the generated copy (short code, e.g. "pl"). Omit for the global value.',
                ],
                'channel' => [
                    'type' => 'string',
                    'description' => 'Channel code when the copy targets one channel; narrows facts to its publication profile.',
                ],
                'recipe_id' => [
                    'type' => 'string',
                    'description' => 'ContentRecipe UUID. Omit to use the recipe configured for the target attribute.',
                ],
                'tone_hint' => [
                    'type' => 'string',
                    'description' => 'Optional one-off tone adjustment on top of the recipe/brand voice.',
                ],
                'pending_change_batch_id' => [
                    'type' => 'string',
                    'description' => 'Append to an existing proposal batch (multi-step plans accumulate into one approval). Usually injected automatically.',
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

        $grounding = $this->grounding->ground(
            Uuid::fromString($productId),
            $recipe,
            $context->tenant,
            $locale,
            $channel,
        );

        $verdict = $this->gate->evaluate($grounding, $recipe);
        if (!$verdict->isSufficient()) {
            // Structured refusal, not an exception: the model relays which
            // facts to fill instead of inventing them (SEC, P2-02).
            return $verdict->toToolResult();
        }

        $voice = $this->resolveVoice($recipe);
        $toneHint = \is_string($arguments['tone_hint'] ?? null) && '' !== $arguments['tone_hint'] ? $arguments['tone_hint'] : null;

        $model = $this->models->defaultModel();
        $response = $this->llm->create(
            $context->tenant,
            $model,
            $this->systemPrompt($recipe, $voice, $toneHint),
            [[
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => $this->userPrompt($grounding, $recipe, $targetAttribute, $locale)]],
            ]],
            tools: [],
            promptCaching: false,
        );
        $generated = trim($this->responseText($response->contentBlocks));
        if ('' === $generated) {
            return ['error' => 'The model returned no text — retry or adjust the recipe.'];
        }

        $warnings = [];
        $maxLen = $recipe->getConstraints()['max_len'] ?? null;
        if (\is_int($maxLen) && mb_strlen($generated) > $maxLen) {
            $warnings[] = \sprintf('Generated %d characters against the recipe budget of %d — review before accepting.', mb_strlen($generated), $maxLen);
        }

        $batchId = $this->resolveBatch($arguments['pending_change_batch_id'] ?? null);
        $proposal = $this->contentValues->materializeGeneratedValue(
            $batchId,
            $context->userId,
            Uuid::fromString($productId),
            $targetAttribute,
            $generated,
            $locale,
            $channel,
            meta: [
                'intent' => 'generate_product_description',
                'source_attributes' => $grounding->usedCodes,
                'recipe_id' => $recipe->getId()->toRfc4122(),
                'model' => $model,
            ],
        );

        $usage = [
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cost_usd' => $this->costs->costUsd($model, $response->inputTokens, $response->outputTokens, $response->cacheReadTokens, $response->cacheCreationTokens),
        ];

        if (!$proposal->isMaterialized()) {
            return $proposal->toToolResult() + ['llm_usage' => $usage];
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
            'warnings' => $warnings,
            'llm_usage' => $usage,
            'note' => 'Proposal materialized for approval — nothing was written to the product.',
        ];
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
        $criteria = \is_string($target) && '' !== $target ? ['targetAttribute' => $target] : ['code' => 'product_description'];
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

    private function resolveBatch(mixed $requested): Uuid
    {
        return \is_string($requested) && Uuid::isValid($requested) ? Uuid::fromString($requested) : Uuid::v7();
    }

    private function systemPrompt(ContentRecipe $recipe, ?BrandVoiceProfile $voice, ?string $toneHint): string
    {
        $constraints = $recipe->getConstraints();
        $format = \is_string($constraints['format'] ?? null) ? $constraints['format'] : 'plain';
        $maxLen = $constraints['max_len'] ?? null;

        $lines = [
            'You are a product copywriter inside a PIM. You write from structured product data.',
            '',
            'ANTI-HALLUCINATION CONTRACT (hard rules):',
            '- Use ONLY the facts provided in the user message. They are the complete ground truth.',
            '- NEVER state a parameter, number, material, feature or property that is absent from the facts.',
            '- Do not speculate, average, or "typically" anything. Missing means unmentioned.',
            '',
            \sprintf('Output format: %s.%s', 'html' === $format ? 'clean semantic HTML (p/ul/li/strong only, no styles, no scripts)' : 'plain text (no markup)', \is_int($maxLen) ? \sprintf(' Stay within ~%d characters.', $maxLen) : ''),
            'Reply with the copy ONLY - no preamble, no commentary, no quotes around it.',
        ];

        if (null !== $recipe->getToneHint() && '' !== $recipe->getToneHint()) {
            $lines[] = 'Tone (recipe): '.$recipe->getToneHint();
        }
        if (null !== $toneHint) {
            $lines[] = 'Tone (one-off override for this request): '.$toneHint;
        }

        if (null !== $voice) {
            $lines[] = '';
            $lines[] = \sprintf('Brand voice "%s": %s', $voice->getName(), $voice->getTone());
            foreach ($voice->getGlossary() as $entry) {
                $lines[] = \sprintf('- Always phrase "%s" as "%s".', $entry['term'], $entry['use']);
            }
            if ([] !== $voice->getBannedWords()) {
                $lines[] = '- Never use the words: '.implode(', ', $voice->getBannedWords()).'.';
            }
            $example = $voice->getExamples()[0] ?? null;
            if (null !== $example) {
                $lines[] = \sprintf('- GOOD copy example: "%s"', $example['good']);
                $lines[] = \sprintf('- BAD copy example (never write like this): "%s"', $example['bad']);
            }
        }

        return implode("\n", $lines);
    }

    private function userPrompt(ContentGrounding $grounding, ContentRecipe $recipe, string $targetAttribute, ?string $locale): string
    {
        $facts = json_encode($grounding->facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $siblings = [] === $grounding->siblingLocales
            ? ''
            : "\n\nExisting copy in other locales (keep the substance consistent with it):\n"
                .json_encode($grounding->siblingLocales, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $channelNote = [] === $grounding->channelContext
            ? ''
            : "\n\nChannel context: ".json_encode($grounding->channelContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return \sprintf(
            "Write the \"%s\" attribute%s for this product.\n\nProduct facts (the ONLY permissible source of claims):\n%s%s%s",
            $targetAttribute,
            null !== $locale ? \sprintf(' in locale "%s"', $locale) : '',
            \is_string($facts) ? $facts : '{}',
            $siblings,
            $channelNote,
        );
    }

    /**
     * @param list<array<string, mixed>> $contentBlocks
     */
    private function responseText(array $contentBlocks): string
    {
        $text = '';
        foreach ($contentBlocks as $block) {
            if (($block['type'] ?? null) === 'text' && \is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        return $text;
    }
}
