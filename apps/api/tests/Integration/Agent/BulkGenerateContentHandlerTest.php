<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Application\AgentFeatureGuard;
use App\Agent\Application\Content\BulkGenerateContentHandler;
use App\Agent\Application\Content\BulkGenerateContentMessage;
use App\Agent\Application\Content\ContentGroundingService;
use App\Agent\Application\Content\GroundingGate;
use App\Agent\Application\Llm\AgentLlmClientInterface;
use App\Agent\Application\Llm\AgentLlmResponse;
use App\Agent\Application\Run\UsageCostCalculator;
use App\Agent\Application\Tool\GenerateProductDescriptionTool;
use App\Agent\Domain\AgentRunStatus;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Domain\Entity\ContentRecipe;
use App\Agent\Infrastructure\Anthropic\AgentModelSelector;
use App\Catalog\Application\PendingChanges\ContentValueMaterializer;
use App\Catalog\Application\Validation\AttributeValueValidator;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectValueRepositoryInterface;
use App\Channel\Contracts\ChannelResolverInterface;
use App\Identity\Contracts\Byok\ByokKeyResolverInterface;
use App\Identity\Contracts\Policy\UserScopedPermissionCheckerInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AICG-P6-03 (#2346) — the dedicated bulk content handler against real
 * Postgres with a scripted LLM (external API is the only mock):
 *
 *   - every product's proposal lands in the ONE run batch, run ->
 *     awaiting_approval, nothing applied to the catalog (approval-only);
 *   - with batchSize=1 the flush+clear fires between every product and
 *     the run still finalizes correctly (the memory-bounded path re-
 *     resolves the detached run + tenant, §3.10);
 *   - a product with no facts is SKIPPED (insufficient grounding), never
 *     invented, and never sinks the rest of the batch.
 */
final class BulkGenerateContentHandlerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function everyProductMaterializesIntoTheOneBatchAndNothingIsApplied(): void
    {
        [$tenant, $em] = $this->fixture();
        $recipe = $this->recipe($em);
        $productA = $this->product($em, 'BULK-A', material: 'aluminium');
        $productB = $this->product($em, 'BULK-B', material: 'stal');
        $run = $this->seedRun($em);
        $batchId = Uuid::v7();

        $this->handler($em, reply: 'Rzeczowy opis produktu.', batchSize: 200)(
            new BulkGenerateContentMessage(
                runId: $run->getId(),
                tenantId: $tenant->getId(),
                batchId: $batchId,
                productIds: [$productA->getId()->toRfc4122(), $productB->getId()->toRfc4122()],
                toolName: 'generate_product_description',
                recipeId: $recipe->getId()->toRfc4122(),
            ),
        );

        $run = $em->find(AgentRun::class, $run->getId());
        self::assertInstanceOf(AgentRun::class, $run);
        self::assertSame(AgentRunStatus::AwaitingApproval, $run->getStatus());
        self::assertSame($batchId->toRfc4122(), $run->getPendingChangeBatchId()?->toRfc4122());

        $rows = self::getContainer()->get(PendingChangesPort::class)->listBatch($batchId);
        self::assertCount(2, $rows, 'both products propose into the single batch');
        foreach ($rows as $row) {
            self::assertSame('description', $row->attributeCode);
        }

        // Only the seeded source values exist; the generated description
        // is a PENDING proposal, never an applied object_value.
        $applied = $em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM object_values ov JOIN attributes a ON a.id = ov.attribute_id WHERE a.code = 'description'",
        );
        self::assertSame(0, (int) (\is_scalar($applied) ? $applied : -1), 'approval-only: nothing written to the catalog');
    }

    #[Test]
    public function flushAndClearBetweenProductsStillFinalizesTheRun(): void
    {
        [$tenant, $em] = $this->fixture();
        $recipe = $this->recipe($em);
        $ids = [];
        foreach (['C1', 'C2', 'C3'] as $code) {
            $ids[] = $this->product($em, $code, material: 'aluminium')->getId()->toRfc4122();
        }
        $run = $this->seedRun($em);
        $batchId = Uuid::v7();

        // batchSize=1 forces a flush+clear after EVERY product.
        $this->handler($em, reply: 'Opis.', batchSize: 1)(
            new BulkGenerateContentMessage(
                runId: $run->getId(),
                tenantId: $tenant->getId(),
                batchId: $batchId,
                productIds: $ids,
                toolName: 'generate_product_description',
                recipeId: $recipe->getId()->toRfc4122(),
            ),
        );

        $run = $em->find(AgentRun::class, $run->getId());
        self::assertInstanceOf(AgentRun::class, $run);
        self::assertSame(AgentRunStatus::AwaitingApproval, $run->getStatus());
        self::assertSame(3, $run->getAffectedCount());
        self::assertCount(3, self::getContainer()->get(PendingChangesPort::class)->listBatch($batchId));
    }

    #[Test]
    public function aProductWithoutFactsIsSkippedNotInvented(): void
    {
        [$tenant, $em] = $this->fixture();
        $recipe = $this->recipe($em);
        $withFacts = $this->product($em, 'HAS-FACTS', material: 'aluminium');
        $empty = $this->product($em, 'NO-FACTS', material: null);
        $run = $this->seedRun($em);
        $batchId = Uuid::v7();

        $this->handler($em, reply: 'Opis.', batchSize: 200)(
            new BulkGenerateContentMessage(
                runId: $run->getId(),
                tenantId: $tenant->getId(),
                batchId: $batchId,
                productIds: [$withFacts->getId()->toRfc4122(), $empty->getId()->toRfc4122()],
                toolName: 'generate_product_description',
                recipeId: $recipe->getId()->toRfc4122(),
            ),
        );

        $rows = self::getContainer()->get(PendingChangesPort::class)->listBatch($batchId);
        self::assertCount(1, $rows, 'only the grounded product proposes; the empty one is skipped, not invented');
        self::assertSame($withFacts->getId()->toRfc4122(), $rows[0]->targetObjectId?->toRfc4122());
    }

    /**
     * @return array{0: Tenant, 1: EntityManagerInterface}
     */
    private function fixture(): array
    {
        $tenant = new Tenant('alpha', 'Alpha Tenant');
        $em = $this->em();
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        return [$tenant, $em];
    }

    private function recipe(EntityManagerInterface $em): ContentRecipe
    {
        $recipe = new ContentRecipe(
            code: 'product_description',
            name: 'Opis produktu',
            targetAttribute: 'description',
            sourceAttributes: ['material'],
            constraints: ['format' => 'plain'],
        );
        $em->persist($recipe);
        $em->flush();

        return $recipe;
    }

    private function product(EntityManagerInterface $em, string $code, ?string $material): CatalogObject
    {
        $type = $em->getRepository(ObjectType::class)->findOneBy(['code' => 'product']);
        if (!$type instanceof ObjectType) {
            $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
            $em->persist($type);
            $em->persist(new Attribute('material', ['en' => 'Material'], AttributeType::Text));
            $em->persist(new Attribute('description', ['en' => 'Description'], AttributeType::Text));
            $em->flush();
        }
        $object = new CatalogObject($type, $code);
        $em->persist($object);
        if (null !== $material) {
            $attribute = $em->getRepository(Attribute::class)->findOneBy(['code' => 'material']);
            \assert($attribute instanceof Attribute);
            $em->persist(new ObjectValue($object, $attribute, ['value' => $material]));
        }
        $em->flush();

        return $object;
    }

    private function seedRun(EntityManagerInterface $em): AgentRun
    {
        $run = new AgentRun(Uuid::v7(), AgentRunSurface::CmdK, 'bulk content');
        $em->persist($run);
        $em->flush();

        return $run;
    }

    private function handler(EntityManagerInterface $em, string $reply, int $batchSize): BulkGenerateContentHandler
    {
        $keyResolver = $this->createStub(ByokKeyResolverInterface::class);
        $keyResolver->method('hasActiveKey')->willReturn(true);
        $guard = new AgentFeatureGuard($keyResolver, true);

        return new BulkGenerateContentHandler($em, [$this->contentTool($em, $reply)], $guard, $batchSize);
    }

    private function contentTool(EntityManagerInterface $em, string $reply): GenerateProductDescriptionTool
    {
        $selector = new AgentModelSelector('claude-sonnet-test', 'claude-opus-test');
        $rbac = new class implements UserScopedPermissionCheckerInterface {
            public function canViewAttribute(Uuid $userId, Uuid $attributeId): bool
            {
                return true;
            }

            public function canEditAttribute(Uuid $userId, Uuid $attributeId): bool
            {
                return true;
            }

            public function canEditLocale(Uuid $userId, string $locale): bool
            {
                return true;
            }

            public function canEditChannel(Uuid $userId, string $channel): bool
            {
                return true;
            }
        };
        $materializer = new ContentValueMaterializer(
            $em,
            self::getContainer()->get(TenantContext::class),
            self::getContainer()->get(ObjectValueRepositoryInterface::class),
            self::getContainer()->get(AttributeValueValidator::class),
            $rbac,
            self::getContainer()->get(ChannelResolverInterface::class),
            self::getContainer()->get(PendingChangesPort::class),
        );

        return new GenerateProductDescriptionTool(
            $em,
            self::getContainer()->get(ContentGroundingService::class),
            new GroundingGate(),
            $materializer,
            $this->scriptedLlm($reply),
            $selector,
            new UsageCostCalculator($selector, 3.0, 15.0, 5.0, 25.0),
        );
    }

    private function scriptedLlm(string $reply): AgentLlmClientInterface
    {
        return new class($reply) implements AgentLlmClientInterface {
            public function __construct(private readonly string $reply)
            {
            }

            public function create(Tenant $tenant, string $model, string $system, array $messages, array $tools, bool $promptCaching = true): AgentLlmResponse
            {
                return new AgentLlmResponse('end_turn', [['type' => 'text', 'text' => $this->reply]], 12, 34);
            }
        };
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
