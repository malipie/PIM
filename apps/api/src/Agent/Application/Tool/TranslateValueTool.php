<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Agent\Application\Llm\AgentLlmClientInterface;
use App\Agent\Application\Run\UsageCostCalculator;
use App\Agent\Domain\Entity\BrandVoiceProfile;
use App\Agent\Infrastructure\Anthropic\AgentModelSelector;
use App\Catalog\Contracts\Command\ContentValuePort;
use App\Catalog\Contracts\Query\ObjectFactsPort;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * AICG-P6-01 (#2344, ADR-0030) — translation as the third variant of
 * the grounded mechanism (plan §3a pkt 2-3): the FACT is the existing
 * value in the source locale, the output is the same attribute in the
 * target locale — consistency, not re-invention. No source value means
 * insufficient grounding, never a from-scratch generation.
 *
 * Recipe-less by design: what to write is fully determined by the
 * source value; only the brand voice (pinned or tenant default) shapes
 * HOW it reads in the target language.
 */
final readonly class TranslateValueTool implements AgentToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private ObjectFactsPort $facts,
        private ContentValuePort $contentValues,
        private AgentLlmClientInterface $llm,
        private AgentModelSelector $models,
        private UsageCostCalculator $costs,
    ) {
    }

    public function name(): string
    {
        return 'translate_value';
    }

    public function description(): string
    {
        return 'Translate an existing text attribute value from one locale to another, preserving its substance exactly (no added or dropped claims). The proposal lands in pending_changes for human approval. Use when the user asks to translate or localise a field; the source-locale value must exist — with no source value the tool returns insufficient_grounding.';
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
                    'description' => 'Code of the text attribute to translate (e.g. "description").',
                ],
                'source_locale' => [
                    'type' => 'string',
                    'description' => 'Locale the existing value is read from (short code, e.g. "pl").',
                ],
                'target_locale' => [
                    'type' => 'string',
                    'description' => 'Locale the translation is written to (short code, e.g. "en").',
                ],
                'channel' => [
                    'type' => 'string',
                    'description' => 'Channel code when the value is channel-scoped.',
                ],
                'brand_voice_id' => [
                    'type' => 'string',
                    'description' => 'BrandVoiceProfile UUID. Omit to use the tenant default.',
                ],
                'pending_change_batch_id' => [
                    'type' => 'string',
                    'description' => 'Append to an existing proposal batch. Usually injected automatically.',
                ],
            ],
            'required' => ['product_id', 'target_attribute', 'source_locale', 'target_locale'],
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
        $targetAttribute = $arguments['target_attribute'] ?? null;
        $sourceLocale = $arguments['source_locale'] ?? null;
        $targetLocale = $arguments['target_locale'] ?? null;
        if (!\is_string($targetAttribute) || '' === $targetAttribute
            || !\is_string($sourceLocale) || '' === $sourceLocale
            || !\is_string($targetLocale) || '' === $targetLocale) {
            return ['error' => 'target_attribute, source_locale and target_locale are required.'];
        }
        if ($sourceLocale === $targetLocale) {
            return ['error' => 'source_locale and target_locale must differ.'];
        }
        $channel = \is_string($arguments['channel'] ?? null) && '' !== $arguments['channel'] ? $arguments['channel'] : null;

        try {
            $facts = $this->facts->facts(Uuid::fromString($productId), [$targetAttribute], $sourceLocale, $channel);
        } catch (InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }
        $sourceEnvelope = $facts->values[$targetAttribute] ?? null;
        $sourceText = \is_array($sourceEnvelope) && \is_string($sourceEnvelope['value'] ?? null)
            ? trim($sourceEnvelope['value'])
            : '';
        if ('' === $sourceText) {
            // The anti-hallucination fuse of translation: no source value
            // means nothing to translate — never invent (P2-02 semantics).
            return [
                'status' => 'insufficient_grounding',
                'missing_source_attributes' => [\sprintf('%s@%s', $targetAttribute, $sourceLocale)],
                'present_facts' => 0,
            ];
        }

        $voice = $this->resolveVoice($arguments['brand_voice_id'] ?? null);
        $model = $this->models->defaultModel();
        $response = $this->llm->create(
            $context->tenant,
            $model,
            $this->systemPrompt($sourceLocale, $targetLocale, $voice),
            [[
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => $sourceText]],
            ]],
            tools: [],
            promptCaching: false,
        );
        $translated = '';
        foreach ($response->contentBlocks as $block) {
            if (($block['type'] ?? null) === 'text' && \is_string($block['text'] ?? null)) {
                $translated .= $block['text'];
            }
        }
        $translated = trim($translated);
        if ('' === $translated) {
            return ['error' => 'The model returned no text — retry.'];
        }

        $batchId = \is_string($arguments['pending_change_batch_id'] ?? null) && Uuid::isValid($arguments['pending_change_batch_id'])
            ? Uuid::fromString($arguments['pending_change_batch_id'])
            : Uuid::v7();
        $sourceRef = \sprintf('%s@%s', $targetAttribute, $sourceLocale);
        $proposal = $this->contentValues->materializeGeneratedValue(
            $batchId,
            $context->userId,
            Uuid::fromString($productId),
            $targetAttribute,
            $translated,
            $targetLocale,
            $channel,
            meta: [
                'intent' => 'translate',
                'source_attributes' => [$sourceRef],
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
            'source_locale' => $sourceLocale,
            'locale' => $targetLocale,
            'channel' => $proposal->scopeChannel,
            'generated_preview' => mb_substr($translated, 0, 300),
            'source_attributes' => [$sourceRef],
            'llm_usage' => $usage,
            'note' => 'Translation proposal materialized for approval — nothing was written to the product.',
        ];
    }

    private function resolveVoice(mixed $brandVoiceId): ?BrandVoiceProfile
    {
        if (\is_string($brandVoiceId) && Uuid::isValid($brandVoiceId)) {
            $pinned = $this->em->find(BrandVoiceProfile::class, $brandVoiceId);
            if ($pinned instanceof BrandVoiceProfile) {
                return $pinned;
            }
        }

        return $this->em->getRepository(BrandVoiceProfile::class)->findOneBy(['isDefault' => true]);
    }

    private function systemPrompt(string $sourceLocale, string $targetLocale, ?BrandVoiceProfile $voice): string
    {
        $lines = [
            \sprintf('You are a product-copy translator inside a PIM. Translate the user message from locale "%s" to locale "%s".', $sourceLocale, $targetLocale),
            '',
            'HARD RULES:',
            '- Preserve the substance EXACTLY: every claim, number, dimension and property of the source appears in the translation; nothing is added, dropped or "improved".',
            '- Keep the original formatting (plain text stays plain, HTML tags stay as they are).',
            '- Reply with the translation ONLY - no preamble, no commentary, no quotes around it.',
        ];
        if (null !== $voice) {
            $lines[] = \sprintf('Brand voice "%s": %s', $voice->getName(), $voice->getTone());
            if ([] !== $voice->getBannedWords()) {
                $lines[] = '- Never use the words: '.implode(', ', $voice->getBannedWords()).'.';
            }
        }

        return implode("\n", $lines);
    }
}
