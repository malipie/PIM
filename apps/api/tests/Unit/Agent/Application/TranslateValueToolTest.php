<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Application;

use App\Agent\Application\Llm\AgentLlmClientInterface;
use App\Agent\Application\Llm\AgentLlmResponse;
use App\Agent\Application\Run\UsageCostCalculator;
use App\Agent\Application\Tool\AgentToolContext;
use App\Agent\Application\Tool\ToolKind;
use App\Agent\Application\Tool\TranslateValueTool;
use App\Agent\Domain\Entity\BrandVoiceProfile;
use App\Agent\Infrastructure\Anthropic\AgentModelSelector;
use App\Catalog\Contracts\Command\ContentValuePort;
use App\Catalog\Contracts\Command\ContentValueProposal;
use App\Catalog\Contracts\Query\ObjectFacts;
use App\Catalog\Contracts\Query\ObjectFactsPort;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AICG-P6-01 (#2344) — translation as the third grounded variant: the
 * only fact is the value in the source locale. The two guarantees under
 * test mirror the flagship tool's:
 *
 *   - no source value -> insufficient_grounding BEFORE any model call
 *     (translation never invents a source),
 *   - the nested call receives the source text alone, and the proposal
 *     lands in the TARGET locale with intent=translate + the
 *     "attr@source_locale" audit ref.
 */
final class TranslateValueToolTest extends TestCase
{
    #[Test]
    public function missingSourceValueShortCircuitsBeforeTheLlm(): void
    {
        $calls = new TranslationLlmCallRecorder();
        $tool = $this->tool(sourceValues: [], llm: $this->scriptedLlm('SHOULD NEVER BE CALLED', $calls));

        $result = $tool->execute([
            'product_id' => Uuid::v7()->toRfc4122(),
            'target_attribute' => 'description',
            'source_locale' => 'pl',
            'target_locale' => 'en',
        ], $this->context());

        self::assertSame('insufficient_grounding', $result['status']);
        self::assertSame(['description@pl'], $result['missing_source_attributes']);
        self::assertSame(0, $calls->count, 'no source value means NO model call — nothing to translate');
    }

    #[Test]
    public function happyPathMaterializesInTheTargetLocaleWithAuditMeta(): void
    {
        $calls = new TranslationLlmCallRecorder();
        $port = new class implements ContentValuePort {
            /** @var array<string, mixed> */
            public array $capturedMeta = [];
            public ?string $capturedText = null;
            public ?string $capturedLocale = null;

            public function materializeGeneratedValue(Uuid $batchId, Uuid $userId, Uuid $objectId, string $attributeCode, string $generatedText, ?string $locale = null, ?string $channel = null, array $meta = []): ContentValueProposal
            {
                $this->capturedMeta = $meta;
                $this->capturedText = $generatedText;
                $this->capturedLocale = $locale;

                return ContentValueProposal::materialized(null, ['value' => $generatedText], $locale, $channel);
            }
        };

        $tool = $this->tool(
            sourceValues: ['description' => ['value' => 'Kurtka z aluminiową membraną.']],
            llm: $this->scriptedLlm('A jacket with an aluminium membrane.', $calls),
            port: $port,
        );
        $result = $tool->execute([
            'product_id' => Uuid::v7()->toRfc4122(),
            'target_attribute' => 'description',
            'source_locale' => 'pl',
            'target_locale' => 'en',
        ], $this->context());

        self::assertSame('materialized', $result['status']);
        self::assertSame(1, $result['affected_count']);
        self::assertSame('pl', $result['source_locale']);
        self::assertSame('en', $result['locale']);
        self::assertSame(['description@pl'], $result['source_attributes']);
        self::assertSame('en', $port->capturedLocale, 'the proposal must land in the TARGET locale');
        self::assertSame('A jacket with an aluminium membrane.', $port->capturedText);
        self::assertSame('translate', $port->capturedMeta['intent']);
        self::assertSame(['description@pl'], $port->capturedMeta['source_attributes']);
        $usage = $result['llm_usage'];
        self::assertIsArray($usage);
        self::assertSame(12, $usage['input_tokens']);
        self::assertSame(34, $usage['output_tokens']);
    }

    #[Test]
    public function promptCarriesTheSourceTextAndThePreservationContract(): void
    {
        $calls = new TranslationLlmCallRecorder();
        $tool = $this->tool(
            sourceValues: ['description' => ['value' => 'Kurtka z membraną.']],
            llm: $this->scriptedLlm('ok', $calls),
        );

        $tool->execute([
            'product_id' => Uuid::v7()->toRfc4122(),
            'target_attribute' => 'description',
            'source_locale' => 'pl',
            'target_locale' => 'en',
        ], $this->context());

        self::assertSame(1, $calls->count);
        self::assertStringContainsString('Preserve the substance EXACTLY', $calls->system);
        self::assertStringContainsString('from locale "pl" to locale "en"', $calls->system);
        self::assertStringContainsString('Never use the words: tani', $calls->system, 'the default brand voice applies to translations too');
        // The recorder json_encodes the messages, so assert on the
        // ASCII-safe prefix of the source text.
        self::assertStringContainsString('Kurtka z membran', $calls->userText);
    }

    #[Test]
    public function identicalLocalesAndInvalidIdsAreErrorResults(): void
    {
        $calls = new TranslationLlmCallRecorder();
        $tool = $this->tool(
            sourceValues: ['description' => ['value' => 'x']],
            llm: $this->scriptedLlm('x', $calls),
        );

        $samLocale = $tool->execute([
            'product_id' => Uuid::v7()->toRfc4122(),
            'target_attribute' => 'description',
            'source_locale' => 'pl',
            'target_locale' => 'pl',
        ], $this->context());
        self::assertArrayHasKey('error', $samLocale);

        $badId = $tool->execute([
            'product_id' => 'not-a-uuid',
            'target_attribute' => 'description',
            'source_locale' => 'pl',
            'target_locale' => 'en',
        ], $this->context());
        self::assertArrayHasKey('error', $badId);

        $missingParams = $tool->execute([
            'product_id' => Uuid::v7()->toRfc4122(),
        ], $this->context());
        self::assertArrayHasKey('error', $missingParams);

        self::assertSame(0, $calls->count);
    }

    #[Test]
    public function toolIsAWriteToolNamedForTheRegistry(): void
    {
        $tool = $this->tool(sourceValues: [], llm: $this->scriptedLlm('x', new TranslationLlmCallRecorder()));

        self::assertSame('translate_value', $tool->name());
        self::assertSame(ToolKind::Write, $tool->kind());
        self::assertSame('object.write', $tool->requiredPermission());
        self::assertSame(
            ['product_id', 'target_attribute', 'source_locale', 'target_locale'],
            $tool->parametersSchema()['required'],
        );
    }

    /**
     * @param array<string, array<string, mixed>> $sourceValues
     */
    private function tool(
        array $sourceValues,
        AgentLlmClientInterface $llm,
        ?ContentValuePort $port = null,
    ): TranslateValueTool {
        $voice = new BrandVoiceProfile('Ekspercki', 'ekspercki, zwięzły', bannedWords: ['tani']);
        $voice->markDefault(true);
        $voiceRepo = $this->createStub(EntityRepository::class);
        $voiceRepo->method('findOneBy')->willReturn($voice);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($voiceRepo);

        $factsPort = $this->createStub(ObjectFactsPort::class);
        $factsPort->method('facts')->willReturn(new ObjectFacts(
            objectId: Uuid::v7(),
            objectTypeId: Uuid::v7(),
            values: $sourceValues,
            missingCodes: [] === $sourceValues ? ['description'] : [],
        ));

        $models = new AgentModelSelector('claude-sonnet-test', 'claude-opus-test');

        return new TranslateValueTool(
            $em,
            $factsPort,
            $port ?? $this->acceptingPort(),
            $llm,
            $models,
            new UsageCostCalculator($models, 3.0, 15.0, 5.0, 25.0),
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

    private function scriptedLlm(string $reply, TranslationLlmCallRecorder $calls): AgentLlmClientInterface
    {
        return new class($reply, $calls) implements AgentLlmClientInterface {
            public function __construct(private readonly string $reply, private readonly TranslationLlmCallRecorder $calls)
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
final class TranslationLlmCallRecorder
{
    public int $count = 0;
    public string $system = '';
    public string $userText = '';
}
