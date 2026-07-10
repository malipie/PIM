<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Application;

use App\Agent\Application\Content\ContentGroundingService;
use App\Agent\Application\Content\GroundingGate;
use App\Agent\Application\Content\SeoRulesValidator;
use App\Agent\Application\Llm\AgentLlmClientInterface;
use App\Agent\Application\Llm\AgentLlmResponse;
use App\Agent\Application\Run\UsageCostCalculator;
use App\Agent\Application\Tool\AgentToolContext;
use App\Agent\Application\Tool\GenerateSeoTextTool;
use App\Agent\Application\Tool\ToolKind;
use App\Agent\Domain\Entity\BrandVoiceProfile;
use App\Agent\Domain\Entity\ContentRecipe;
use App\Agent\Infrastructure\Anthropic\AgentModelSelector;
use App\Catalog\Contracts\Command\ContentValuePort;
use App\Catalog\Contracts\Command\ContentValueProposal;
use App\Catalog\Contracts\Query\ObjectFacts;
use App\Catalog\Contracts\Query\ObjectFactsPort;
use App\Channel\Contracts\ChannelPublicationResolverInterface;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AICG-P4-02 (#2338) — the SEO variant of the grounded pipeline: the
 * recipe's SEO rules are enforced post-generation, one violation
 * triggers exactly ONE regeneration with the violations spelled out,
 * and copy that still violates materializes WITH the flags (the
 * operator decides — never a silent write, never a silent drop).
 */
final class GenerateSeoTextToolTest extends TestCase
{
    #[Test]
    public function validCopyMaterializesWithNoViolationsAndOneCall(): void
    {
        $recorder = new SeoLlmRecorder(['Kabel HDMI 2.1 do konsol i telewizorów 4K.']);

        $result = $this->tool($recorder)->execute(
            ['product_id' => Uuid::v7()->toRfc4122()],
            $this->context(),
        );

        self::assertSame('materialized', $result['status']);
        self::assertSame([], $result['seo_violations']);
        self::assertSame(1, $recorder->calls);
        self::assertSame(1, $result['affected_count']);
    }

    #[Test]
    public function violationTriggersExactlyOneRegenerationWithTheViolationsSpelledOut(): void
    {
        $recorder = new SeoLlmRecorder([
            'Przewód wideo wysokiej jakości.', // missing keyword
            'Kabel HDMI 2.1 do konsol i telewizorów 4K.', // fixed
        ]);

        $result = $this->tool($recorder)->execute(
            ['product_id' => Uuid::v7()->toRfc4122()],
            $this->context(),
        );

        self::assertSame(2, $recorder->calls);
        self::assertStringContainsString('missing_keyword', $recorder->lastUserText);
        self::assertSame([], $result['seo_violations']);
        self::assertSame('materialized', $result['status']);
    }

    #[Test]
    public function stillViolatingCopyMaterializesWithFlagsNotSilently(): void
    {
        $recorder = new SeoLlmRecorder([
            str_repeat('za dlugi opis bez slowa kluczowego ', 8),
            str_repeat('nadal za dlugi opis bez slowa kluczowego ', 8),
        ]);

        $result = $this->tool($recorder)->execute(
            ['product_id' => Uuid::v7()->toRfc4122()],
            $this->context(),
        );

        self::assertSame(2, $recorder->calls, 'exactly one regeneration, never a loop');
        self::assertSame('materialized', $result['status']);
        $violations = $result['seo_violations'];
        self::assertIsArray($violations);
        self::assertNotSame([], $violations);
        $note = $result['note'];
        self::assertIsString($note);
        self::assertStringContainsString('unresolved SEO violations', $note);
    }

    #[Test]
    public function insufficientGroundingShortCircuitsBeforeTheLlm(): void
    {
        $recorder = new SeoLlmRecorder(['never']);

        $result = $this->tool($recorder, facts: [])->execute(
            ['product_id' => Uuid::v7()->toRfc4122()],
            $this->context(),
        );

        self::assertSame('insufficient_grounding', $result['status']);
        self::assertSame(0, $recorder->calls);
    }

    #[Test]
    public function keywordOverrideAndUsageAggregationAcrossRegeneration(): void
    {
        $recorder = new SeoLlmRecorder([
            'Przewod bez slowa.', // missing overridden keyword "4K"
            'Telewizor 4K z kablem.',
        ]);

        $result = $this->tool($recorder)->execute(
            ['product_id' => Uuid::v7()->toRfc4122(), 'keyword' => '4K'],
            $this->context(),
        );

        self::assertSame('materialized', $result['status']);
        $usage = $result['llm_usage'];
        self::assertIsArray($usage);
        self::assertSame(20, $usage['input_tokens'], 'both calls must be accounted (2 x 10)');
        self::assertSame(40, $usage['output_tokens']);
        self::assertStringContainsString('"4K" must appear exactly once', $recorder->lastSystem);
    }

    #[Test]
    public function toolContractForTheRegistry(): void
    {
        $tool = $this->tool(new SeoLlmRecorder(['x']));

        self::assertSame('generate_seo_text', $tool->name());
        self::assertSame(ToolKind::Write, $tool->kind());
        self::assertSame('object.write', $tool->requiredPermission());
    }

    /**
     * @param array<string, array<string, mixed>>|null $facts
     */
    private function tool(SeoLlmRecorder $recorder, ?array $facts = null): GenerateSeoTextTool
    {
        $facts ??= ['name' => ['value' => 'Kabel HDMI 2.1'], 'brand' => ['value' => 'Vento']];

        $recipe = new ContentRecipe(
            code: 'meta_seo',
            name: 'Meta SEO',
            targetAttribute: 'meta_description',
            sourceAttributes: ['name', 'brand'],
            constraints: ['format' => 'plain', 'seo' => ['keyword' => 'HDMI', 'title_len' => 60, 'meta_len' => 155]],
        );
        $voice = new BrandVoiceProfile('Ekspercki', 'rzeczowy');

        $recipeRepo = $this->createStub(EntityRepository::class);
        $recipeRepo->method('findOneBy')->willReturn($recipe);
        $voiceRepo = $this->createStub(EntityRepository::class);
        $voiceRepo->method('findOneBy')->willReturn($voice);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            static fn (string $class): EntityRepository => ContentRecipe::class === $class ? $recipeRepo : $voiceRepo,
        );

        $factsPort = $this->createStub(ObjectFactsPort::class);
        $factsPort->method('facts')->willReturn(new ObjectFacts(
            objectId: Uuid::v7(),
            objectTypeId: Uuid::v7(),
            values: $facts,
            missingCodes: [] === $facts ? ['name', 'brand'] : [],
        ));

        $port = new class implements ContentValuePort {
            public function materializeGeneratedValue(Uuid $batchId, Uuid $userId, Uuid $objectId, string $attributeCode, string $generatedText, ?string $locale = null, ?string $channel = null, array $meta = []): ContentValueProposal
            {
                return ContentValueProposal::materialized(null, ['value' => $generatedText], $locale, $channel);
            }
        };

        $models = new AgentModelSelector('claude-sonnet-test', 'claude-opus-test');

        return new GenerateSeoTextTool(
            $em,
            new ContentGroundingService($factsPort, $this->createStub(ChannelPublicationResolverInterface::class)),
            new GroundingGate(),
            new SeoRulesValidator(),
            $port,
            $recorder->client(),
            $models,
            new UsageCostCalculator($models, 3.0, 15.0, 5.0, 25.0),
        );
    }

    private function context(): AgentToolContext
    {
        return new AgentToolContext(Uuid::v7(), new Tenant('demo-test-'.bin2hex(random_bytes(2)), 'Demo'));
    }
}

/**
 * Scripted LLM replies + captured prompts across regeneration rounds.
 */
final class SeoLlmRecorder
{
    public int $calls = 0;
    public string $lastSystem = '';
    public string $lastUserText = '';

    /**
     * @param list<string> $replies
     */
    public function __construct(private readonly array $replies)
    {
    }

    public function client(): AgentLlmClientInterface
    {
        $recorder = $this;

        return new class($recorder) implements AgentLlmClientInterface {
            public function __construct(private readonly SeoLlmRecorder $recorder)
            {
            }

            public function create(Tenant $tenant, string $model, string $system, array $messages, array $tools, bool $promptCaching = true): AgentLlmResponse
            {
                $reply = $this->recorder->reply();
                $this->recorder->lastSystem = $system;
                $encoded = json_encode($messages);
                $this->recorder->lastUserText = false === $encoded ? '' : $encoded;

                return new AgentLlmResponse(
                    stopReason: AgentLlmResponse::STOP_END_TURN,
                    contentBlocks: [['type' => 'text', 'text' => $reply]],
                    inputTokens: 10,
                    outputTokens: 20,
                );
            }
        };
    }

    public function reply(): string
    {
        $reply = $this->replies[$this->calls] ?? $this->replies[array_key_last($this->replies)] ?? '';
        ++$this->calls;

        return $reply;
    }
}
