<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Application;

use App\Agent\Application\Content\ContentGrounding;
use App\Agent\Application\Content\ContentGroundingService;
use App\Agent\Application\Content\GroundingGate;
use App\Agent\Application\Llm\AgentLlmClientInterface;
use App\Agent\Application\Llm\AgentLlmResponse;
use App\Agent\Application\Run\UsageCostCalculator;
use App\Agent\Application\Tool\AgentToolContext;
use App\Agent\Application\Tool\GenerateProductDescriptionTool;
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
 * AICG-P3-02 (#2335) — the flagship tool's pipeline with a scripted
 * LLM (external API is the only mock): grounding -> gate -> nested
 * generation -> materialization, and the two hard guarantees:
 *
 *   - insufficient grounding NEVER reaches the model (no invention,
 *     no spend),
 *   - the prompt carries ONLY the grounded facts, and the audit meta
 *     (source_attributes + recipe_id) mirrors them exactly.
 */
final class GenerateProductDescriptionToolTest extends TestCase
{
    #[Test]
    public function insufficientGroundingShortCircuitsBeforeTheLlm(): void
    {
        $calls = new LlmCallRecorder();
        $llm = $this->scriptedLlm('SHOULD NEVER BE CALLED', $calls);

        $tool = $this->tool(
            grounding: new ContentGrounding(facts: [], usedCodes: [], missingCodes: ['material', 'color']),
            llm: $llm,
        );
        $result = $tool->execute(['product_id' => Uuid::v7()->toRfc4122()], $this->context());

        self::assertSame('insufficient_grounding', $result['status']);
        self::assertSame(['material', 'color'], $result['missing_source_attributes']);
        self::assertSame(0, $calls->count, 'the gate must refuse BEFORE any model call');
    }

    #[Test]
    public function happyPathMaterializesWithAuditMetaAndUsage(): void
    {
        $calls = new LlmCallRecorder();
        $llm = $this->scriptedLlm('Rzeczowy opis z aluminium.', $calls);
        $port = new class implements ContentValuePort {
            /** @var array<string, mixed> */
            public array $capturedMeta = [];
            public ?string $capturedText = null;

            public function materializeGeneratedValue(Uuid $batchId, Uuid $userId, Uuid $objectId, string $attributeCode, string $generatedText, ?string $locale = null, ?string $channel = null, array $meta = []): ContentValueProposal
            {
                $this->capturedMeta = $meta;
                $this->capturedText = $generatedText;

                return ContentValueProposal::materialized(null, ['value' => $generatedText], $locale, $channel);
            }
        };

        $tool = $this->tool(
            grounding: new ContentGrounding(
                facts: ['material' => ['value' => 'aluminium'], 'color' => ['option_code' => 'red']],
                usedCodes: ['material', 'color'],
                missingCodes: [],
            ),
            llm: $llm,
            port: $port,
        );
        $result = $tool->execute(
            ['product_id' => Uuid::v7()->toRfc4122(), 'locale' => 'pl'],
            $this->context(),
        );

        self::assertSame('materialized', $result['status']);
        self::assertSame(1, $result['affected_count']);
        self::assertIsString($result['pending_change_batch_id']);
        self::assertSame(['material', 'color'], $result['source_attributes']);
        $usage = $result['llm_usage'];
        self::assertIsArray($usage);
        self::assertSame(12, $usage['input_tokens']);
        self::assertSame(34, $usage['output_tokens']);
        self::assertSame('Rzeczowy opis z aluminium.', $port->capturedText);
        self::assertSame(['material', 'color'], $port->capturedMeta['source_attributes']);
        self::assertArrayHasKey('recipe_id', $port->capturedMeta);
        self::assertSame('generate_product_description', $port->capturedMeta['intent']);
    }

    #[Test]
    public function promptCarriesOnlyGroundedFactsAndTheContract(): void
    {
        $calls = new LlmCallRecorder();
        $llm = $this->scriptedLlm('ok', $calls);

        $tool = $this->tool(
            grounding: new ContentGrounding(
                facts: ['material' => ['value' => 'aluminium']],
                usedCodes: ['material'],
                missingCodes: ['size'],
            ),
            llm: $llm,
        );
        $tool->execute(['product_id' => Uuid::v7()->toRfc4122()], $this->context());

        self::assertSame(1, $calls->count);
        self::assertStringContainsString('ANTI-HALLUCINATION CONTRACT', $calls->system);
        self::assertStringContainsString('Never use the words: tani', $calls->system);
        self::assertStringContainsString('aluminium', $calls->userText);
        self::assertStringNotContainsString('size', $calls->userText, 'missing codes must not leak into the facts payload');
    }

    #[Test]
    public function overlongCopyIsFlaggedNotSilentlyAccepted(): void
    {
        $calls = new LlmCallRecorder();
        $llm = $this->scriptedLlm(str_repeat('a', 50), $calls);

        $tool = $this->tool(
            grounding: new ContentGrounding(facts: ['material' => ['value' => 'x']], usedCodes: ['material'], missingCodes: []),
            llm: $llm,
            maxLen: 10,
        );
        $result = $tool->execute(['product_id' => Uuid::v7()->toRfc4122()], $this->context());

        self::assertSame('materialized', $result['status']);
        $warnings = $result['warnings'];
        self::assertIsArray($warnings);
        self::assertNotSame([], $warnings);
        self::assertIsString($warnings[0]);
        self::assertStringContainsString('budget of 10', $warnings[0]);
    }

    #[Test]
    public function invalidProductIdIsAnErrorResult(): void
    {
        $calls = new LlmCallRecorder();
        $llm = $this->scriptedLlm('x', $calls);
        $result = $this->tool(
            grounding: new ContentGrounding(facts: [], usedCodes: [], missingCodes: []),
            llm: $llm,
        )->execute(['product_id' => 'not-a-uuid'], $this->context());

        self::assertArrayHasKey('error', $result);
        self::assertSame(0, $calls->count);
    }

    #[Test]
    public function toolIsAWriteToolNamedForTheRegistry(): void
    {
        $tool = $this->tool(
            grounding: new ContentGrounding(facts: [], usedCodes: [], missingCodes: []),
            llm: $this->scriptedLlm('x', $calls = new LlmCallRecorder()),
        );

        self::assertSame('generate_product_description', $tool->name());
        self::assertSame(ToolKind::Write, $tool->kind());
        self::assertSame('object.write', $tool->requiredPermission());
        self::assertSame(['product_id'], $tool->parametersSchema()['required']);
    }

    private function tool(
        ContentGrounding $grounding,
        AgentLlmClientInterface $llm,
        ?ContentValuePort $port = null,
        ?int $maxLen = null,
    ): GenerateProductDescriptionTool {
        $constraints = ['format' => 'plain'];
        if (null !== $maxLen) {
            $constraints['max_len'] = $maxLen;
        }
        $recipe = new ContentRecipe(
            code: 'product_description',
            name: 'Opis produktu',
            targetAttribute: 'description',
            sourceAttributes: ['material', 'color', 'size'],
            constraints: $constraints,
        );
        $voice = new BrandVoiceProfile('Ekspercki', 'ekspercki, zwięzły', bannedWords: ['tani']);
        $voice->markDefault(true);

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
            values: $grounding->facts,
            missingCodes: $grounding->missingCodes,
            siblingLocales: $grounding->siblingLocales,
        ));
        $groundingService = new ContentGroundingService($factsPort, $this->createStub(ChannelPublicationResolverInterface::class));

        $models = new AgentModelSelector('claude-sonnet-test', 'claude-opus-test');

        $costs = new UsageCostCalculator($models, 3.0, 15.0, 5.0, 25.0);

        return new GenerateProductDescriptionTool(
            $em,
            $groundingService,
            new GroundingGate(),
            $port ?? $this->acceptingPort(),
            $llm,
            $models,
            $costs,
        );
    }

    private function acceptingPort(): ContentValuePort
    {
        return new class implements ContentValuePort {
            public function materializeGeneratedValue(Uuid $batchId, Uuid $userId, Uuid $objectId, string $attributeCode, string $generatedText, ?string $locale = null, ?string $channel = null, array $meta = []): ContentValueProposal
            {
                return ContentValueProposal::materialized(null, ['value' => $generatedText], $locale, $channel);
            }
        };
    }

    private function scriptedLlm(string $reply, LlmCallRecorder $calls): AgentLlmClientInterface
    {
        return new class($reply, $calls) implements AgentLlmClientInterface {
            public function __construct(private readonly string $reply, private readonly LlmCallRecorder $calls)
            {
            }

            public function create(Tenant $tenant, string $model, string $system, array $messages, array $tools, bool $promptCaching = true): AgentLlmResponse
            {
                ++$this->calls->count;
                $this->calls->system = $system;
                $encoded = json_encode($messages);
                $this->calls->userText = false === $encoded ? '' : $encoded;

                return new AgentLlmResponse(
                    stopReason: AgentLlmResponse::STOP_END_TURN,
                    contentBlocks: [['type' => 'text', 'text' => $this->reply]],
                    inputTokens: 12,
                    outputTokens: 34,
                );
            }
        };
    }

    private function context(): AgentToolContext
    {
        return new AgentToolContext(Uuid::v7(), new Tenant('demo-test-'.bin2hex(random_bytes(2)), 'Demo'));
    }
}

/**
 * Captures what the tool's nested LLM call received.
 */
final class LlmCallRecorder
{
    public int $count = 0;
    public string $system = '';
    public string $userText = '';
}
