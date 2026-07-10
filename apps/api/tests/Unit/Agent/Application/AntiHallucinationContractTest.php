<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Application;

use App\Agent\Application\Content\ContentGroundingService;
use App\Agent\Application\Content\GroundingGate;
use App\Agent\Application\Llm\AgentLlmClientInterface;
use App\Agent\Application\Llm\AgentLlmResponse;
use App\Agent\Application\Run\UsageCostCalculator;
use App\Agent\Application\Tool\AgentToolContext;
use App\Agent\Application\Tool\GenerateProductDescriptionTool;
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
 * AICG-P3-03 (#2336, SEC, ADR-0030) — the anti-hallucination CONTRACT
 * of the content pipeline, pinned adversarially (risk R1: the model
 * must not be able to "add" a parameter that does not exist):
 *
 *   1. the prompt handed to the LLM carries EXCLUSIVELY the grounded
 *      facts — a fact outside recipe.sourceAttributes never reaches
 *      the model, even when the catalog holds it;
 *   2. the hard contract instruction is present in the system prompt;
 *   3. provenance_meta.source_attributes mirrors EXACTLY the codes
 *      whose values entered the prompt — even when a hostile/creative
 *      model output claims parameters from outside the facts, the
 *      audit trail never launders them into "sources";
 *   4. the hallucinated output is materialized ONLY as a pending
 *      proposal (human approval is the enforcement backstop, ADR-0030
 *      decision 5) — never applied by the tool.
 *
 * The LLM is the only mock (scripted adversarial replies).
 */
final class AntiHallucinationContractTest extends TestCase
{
    private const string HALLUCINATION = 'Wodoodporna membrana GoreTex, waga 320 g, atest CE.';

    /** @var array<string, mixed> */
    private array $capturedMeta = [];
    private ?string $capturedText = null;
    private string $capturedSystem = '';
    private string $capturedUser = '';

    #[Test]
    public function promptCarriesExclusivelyTheGroundedFacts(): void
    {
        // The catalog "knows" more than the recipe allows: the port would
        // resolve `internal_margin`, but the recipe's source list does not
        // include it — the grounding service asks only for recipe codes,
        // so it must never reach the prompt.
        $this->runTool(reply: 'Poprawny opis z aluminium.');

        self::assertStringContainsString('aluminium', $this->capturedUser);
        self::assertStringContainsString('czerwony', $this->capturedUser);
        self::assertStringNotContainsString('internal_margin', $this->capturedUser, 'a code outside the recipe must never reach the model');
        self::assertStringNotContainsString('47.5', $this->capturedUser, 'a VALUE outside the recipe must never reach the model');
        self::assertStringNotContainsString('internal_margin', $this->capturedSystem);
    }

    #[Test]
    public function hardContractInstructionIsPresentInTheSystemPrompt(): void
    {
        $this->runTool(reply: 'ok');

        self::assertStringContainsString('ANTI-HALLUCINATION CONTRACT', $this->capturedSystem);
        self::assertStringContainsString('NEVER state a parameter', $this->capturedSystem);
        self::assertStringContainsString('Missing means unmentioned', $this->capturedSystem);
    }

    #[Test]
    public function sourceAttributesAuditExactlyTheUsedCodesEvenForHallucinatedOutput(): void
    {
        // Adversarial: the model output claims GoreTex/weight/CE — none of
        // which are facts. The audit trail must still record ONLY material
        // + color as sources; hallucinated claims are never laundered into
        // provenance.
        $result = $this->runTool(reply: self::HALLUCINATION);

        self::assertSame(['material', 'color'], $this->capturedMeta['source_attributes']);
        self::assertSame(['material', 'color'], $result['source_attributes']);
        self::assertArrayHasKey('recipe_id', $this->capturedMeta);
    }

    #[Test]
    public function hallucinatedOutputLandsOnlyAsAPendingProposalNeverApplied(): void
    {
        $result = $this->runTool(reply: self::HALLUCINATION);

        // The tool's only write is the materialization into the approval
        // gate: the hostile copy is captured verbatim as a PROPOSAL the
        // operator sees as a diff (with the source_attributes tooltip
        // exposing that no fact backs GoreTex), and nothing else happens.
        self::assertSame('materialized', $result['status']);
        self::assertSame(self::HALLUCINATION, $this->capturedText);
        self::assertIsString($result['pending_change_batch_id']);
        self::assertSame('Proposal materialized for approval — nothing was written to the product.', $result['note']);
    }

    /**
     * @return array<string, mixed>
     */
    private function runTool(string $reply): array
    {
        $recipe = new ContentRecipe(
            code: 'product_description',
            name: 'Opis produktu',
            targetAttribute: 'description',
            sourceAttributes: ['material', 'color'],
            constraints: ['format' => 'plain'],
        );
        $voice = new BrandVoiceProfile('Ekspercki', 'ekspercki, zwięzły');

        $recipeRepo = $this->createStub(EntityRepository::class);
        $recipeRepo->method('findOneBy')->willReturn($recipe);
        $voiceRepo = $this->createStub(EntityRepository::class);
        $voiceRepo->method('findOneBy')->willReturn($voice);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            static fn (string $class): EntityRepository => ContentRecipe::class === $class ? $recipeRepo : $voiceRepo,
        );

        // The port honours the requested codes (the reader only resolves
        // what it is asked for) — internal_margin exists in the catalog
        // but is not requested, so it is absent from the fact sheet.
        $factsPort = $this->createStub(ObjectFactsPort::class);
        $factsPort->method('facts')->willReturnCallback(
            /** @param list<string> $codes */
            static function (Uuid $objectId, array $codes): ObjectFacts {
                // Explicit string filter — inline @param docblocks do not
                // survive the formatter (lesson #2266).
                $requested = [];
                foreach ($codes as $code) {
                    if (\is_string($code)) {
                        $requested[] = $code;
                    }
                }
                $catalog = [
                    'material' => ['value' => 'aluminium'],
                    'color' => ['value' => 'czerwony'],
                    'internal_margin' => ['value' => 47.5],
                ];
                $values = array_intersect_key($catalog, array_flip($requested));

                return new ObjectFacts(
                    objectId: $objectId,
                    objectTypeId: Uuid::v7(),
                    values: $values,
                    missingCodes: array_values(array_diff($requested, array_keys($values))),
                );
            },
        );

        $grounding = new ContentGroundingService($factsPort, $this->createStub(ChannelPublicationResolverInterface::class));

        $test = $this;
        $llm = new class($reply, $test) implements AgentLlmClientInterface {
            public function __construct(private readonly string $reply, private readonly AntiHallucinationContractTest $test)
            {
            }

            public function create(Tenant $tenant, string $model, string $system, array $messages, array $tools, bool $promptCaching = true): AgentLlmResponse
            {
                $this->test->captureCall($system, $messages);

                return new AgentLlmResponse(
                    stopReason: AgentLlmResponse::STOP_END_TURN,
                    contentBlocks: [['type' => 'text', 'text' => $this->reply]],
                    inputTokens: 10,
                    outputTokens: 20,
                );
            }
        };

        $port = new class($this) implements ContentValuePort {
            public function __construct(private readonly AntiHallucinationContractTest $test)
            {
            }

            public function materializeGeneratedValue(Uuid $batchId, Uuid $userId, Uuid $objectId, string $attributeCode, string $generatedText, ?string $locale = null, ?string $channel = null, array $meta = []): ContentValueProposal
            {
                $this->test->captureMaterialization($generatedText, $meta);

                return ContentValueProposal::materialized(null, ['value' => $generatedText], $locale, $channel);
            }
        };

        $models = new AgentModelSelector('claude-sonnet-test', 'claude-opus-test');
        $tool = new GenerateProductDescriptionTool(
            $em,
            $grounding,
            new GroundingGate(),
            $port,
            $llm,
            $models,
            new UsageCostCalculator($models, 3.0, 15.0, 5.0, 25.0),
        );

        return $tool->execute(
            ['product_id' => Uuid::v7()->toRfc4122()],
            new AgentToolContext(Uuid::v7(), new Tenant('demo-test-'.bin2hex(random_bytes(2)), 'Demo')),
        );
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    public function captureCall(string $system, array $messages): void
    {
        $this->capturedSystem = $system;
        $encoded = json_encode($messages);
        $this->capturedUser = false === $encoded ? '' : $encoded;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function captureMaterialization(string $generatedText, array $meta): void
    {
        $this->capturedText = $generatedText;
        $this->capturedMeta = $meta;
    }
}
