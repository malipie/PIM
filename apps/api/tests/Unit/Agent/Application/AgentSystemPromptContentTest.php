<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Application;

use App\Agent\Application\Run\AgentSystemPromptBuilder;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Domain\Entity\BrandVoiceProfile;
use App\Agent\Domain\Entity\ContentRecipe;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AICG-P2-03 (#2333, ADR-0030) — brand voice + recipe + the
 * anti-hallucination contract are injected into the system prompt only
 * when the run carries recipe_id / brand_voice_id; every other run
 * produces a byte-identical prompt (backward compat).
 */
final class AgentSystemPromptContentTest extends TestCase
{
    #[Test]
    public function runWithoutContentKeysProducesTheBaselinePrompt(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $builder = new AgentSystemPromptBuilder($em);

        $prompt = $builder->buildContext($this->contentRun(['object_type' => 'product']));

        self::assertStringNotContainsString('Brand voice', $prompt);
        self::assertStringNotContainsString('Content recipe', $prompt);
        self::assertStringNotContainsString('ANTI-HALLUCINATION', $prompt);
    }

    #[Test]
    public function unknownRecipeIdKeepsThePromptBaseline(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);
        $builder = new AgentSystemPromptBuilder($em);

        $prompt = $builder->buildContext($this->contentRun(['recipe_id' => Uuid::v7()->toRfc4122()]));

        self::assertStringNotContainsString('Content recipe', $prompt);
        self::assertStringNotContainsString('ANTI-HALLUCINATION', $prompt);
    }

    #[Test]
    public function brandVoiceInjectsToneBannedWordsAndTheContract(): void
    {
        $voice = new BrandVoiceProfile(
            name: 'Ekspercki',
            tone: 'ekspercki, zwięzły',
            glossary: [['term' => 'smart TV', 'use' => 'telewizor smart']],
            bannedWords: ['tani', 'promocja'],
            examples: [['good' => 'Rzeczowy opis.', 'bad' => 'Super okazja!!!']],
        );
        $builder = new AgentSystemPromptBuilder($this->emReturning($voice));

        $prompt = $builder->buildContext($this->contentRun(['brand_voice_id' => Uuid::v7()->toRfc4122()]));

        self::assertStringContainsString('Brand voice "Ekspercki"', $prompt);
        self::assertStringContainsString('ekspercki, zwięzły', $prompt);
        self::assertStringContainsString('Banned words (never use): tani, promocja', $prompt);
        self::assertStringContainsString('"smart TV" -> "telewizor smart"', $prompt);
        self::assertStringContainsString('BAD copy example', $prompt);
        self::assertStringContainsString('ANTI-HALLUCINATION CONTRACT', $prompt);
        self::assertStringContainsString('NEVER state a parameter', $prompt);
    }

    #[Test]
    public function recipeInjectsTargetFormatSeoRulesAndToneHint(): void
    {
        $recipe = new ContentRecipe(
            code: 'meta_seo',
            name: 'Meta SEO',
            targetAttribute: 'meta_description',
            sourceAttributes: ['name', 'brand'],
            constraints: ['format' => 'plain', 'max_len' => 300, 'seo' => ['keyword' => 'HDMI', 'title_len' => 60, 'meta_len' => 155]],
        );
        $recipe->updateToneHint('rzeczowy');
        $builder = new AgentSystemPromptBuilder($this->emReturning($recipe));

        $prompt = $builder->buildContext($this->contentRun(['recipe_id' => Uuid::v7()->toRfc4122()]));

        self::assertStringContainsString('Content recipe "Meta SEO"', $prompt);
        self::assertStringContainsString('Target attribute: meta_description', $prompt);
        self::assertStringContainsString('Output format: plain; max length 300 characters', $prompt);
        self::assertStringContainsString('focus keyword "HDMI"', $prompt);
        self::assertStringContainsString('title at most 60 characters', $prompt);
        self::assertStringContainsString('meta description at most 155 characters', $prompt);
        self::assertStringContainsString('No keyword stuffing', $prompt);
        self::assertStringContainsString('Tone hint: rzeczowy', $prompt);
        self::assertStringContainsString('ANTI-HALLUCINATION CONTRACT', $prompt);
    }

    #[Test]
    public function recipeAttachedVoiceIsResolvedWhenContextOmitsIt(): void
    {
        $voiceId = Uuid::v7();
        $recipe = new ContentRecipe('r', 'R', 'description');
        $recipe->attachBrandVoice($voiceId);
        $voice = new BrandVoiceProfile('Przypięty', 'swobodny');

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            static fn (string $class, mixed $id): ContentRecipe|BrandVoiceProfile|null => match ($class) {
                ContentRecipe::class => $recipe,
                BrandVoiceProfile::class => $id === $voiceId->toRfc4122() ? $voice : null,
                default => null,
            },
        );

        $prompt = new AgentSystemPromptBuilder($em)->buildContext($this->contentRun(['recipe_id' => Uuid::v7()->toRfc4122()]));

        self::assertStringContainsString('Brand voice "Przypięty"', $prompt);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function contentRun(array $context): AgentRun
    {
        return new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'write the copy', $context);
    }

    private function emReturning(object $entity): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            static fn (string $class, mixed $id): ?object => $entity instanceof $class ? $entity : null,
        );

        return $em;
    }
}
