<?php

declare(strict_types=1);

namespace App\Agent\Presentation\Controller;

use App\Agent\Application\AgentFeatureGuard;
use App\Agent\Application\Content\BulkGenerateContentMessage;
use App\Agent\Application\Cost\AgentCostAggregator;
use App\Agent\Application\Cost\ContentCostEstimate;
use App\Agent\Application\Cost\ContentCostEstimator;
use App\Agent\Application\Limits\AgentLimitGuard;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Domain\Entity\ContentRecipe;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use App\Shared\Application\UserIdentityAware;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * AICG-P6-03 (#2346, ADR-0030) — the bulk content surface (plan §8 R3):
 *
 *  - cost-preview: a deterministic pre-flight estimate (tokens + USD)
 *    for N products × recipe, plus the tenant's remaining day budget,
 *    so the modal shows the price and blocks BEFORE spending;
 *  - bulk-generate: the dedicated bulk path — one AgentRun whose write
 *    tool runs per product in a memory-bounded worker batch (not the
 *    10-tool-call agent loop), all proposals in ONE approval batch.
 *
 * The §8.5 day cap is enforced twice: the already-spent check
 * (AgentLimitGuard, shared with run start) AND the estimate-vs-remaining
 * check here — either over the cap answers 429 before any tokens burn.
 */
final readonly class AgentContentController
{
    /** @var array<string, array{tool: string, recipe: string}> */
    private const array MODES = [
        'descriptions' => ['tool' => 'generate_product_description', 'recipe' => 'product_description'],
        'seo' => ['tool' => 'generate_seo_text', 'recipe' => 'meta_seo'],
    ];

    public function __construct(
        private AgentFeatureGuard $featureGuard,
        private ContentCostEstimator $estimator,
        private AgentCostAggregator $costAggregator,
        private AgentLimitGuard $limits,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private TenantContext $tenantContext,
        private Security $security,
    ) {
    }

    #[Route('/api/agent/content/cost-preview', name: 'pim_agent_content_cost_preview', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'settings', action: 'ai_content.read')]
    public function costPreview(Request $request): JsonResponse
    {
        $this->featureGuard->assertEnabled($this->tenant());

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $count = $this->productCount($body);
        [, $recipe] = $this->resolveMode($body);

        $estimate = $this->estimator->estimate($count, $recipe);

        return new JsonResponse($this->serializeEstimate($estimate));
    }

    #[Route('/api/agent/content/bulk-generate', name: 'pim_agent_content_bulk_generate', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'settings', action: 'ai_content.create')]
    public function bulkGenerate(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        $this->featureGuard->assertEnabled($tenant);

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $productIds = $this->productIds($body);
        if ([] === $productIds) {
            throw new BadRequestHttpException('selected_ids must be a non-empty list of product UUIDs.');
        }
        [$toolName, $recipe] = $this->resolveMode($body);
        $locale = \is_string($body['locale'] ?? null) && '' !== $body['locale'] ? $body['locale'] : null;
        $channel = \is_string($body['channel'] ?? null) && '' !== $body['channel'] ? $body['channel'] : null;

        // §8.5 day cap, part 1: what is already spent this UTC day (throws
        // 429 when the tenant is at the cap regardless of this request).
        $userId = $this->userId();
        $this->limits->assertWithinLimits($tenant, $userId);

        // §8.5 day cap, part 2: would THIS run's estimate overshoot the
        // remaining budget? Refuse before spending a token.
        $estimate = $this->estimator->estimate(\count($productIds), $recipe);
        $report = $this->costAggregator->report();
        $remaining = max(0.0, $report->dayCapUsd - (float) $report->costTodayUsd);
        if ((float) $estimate->estCostUsd > $remaining) {
            return new JsonResponse([
                'type' => 'https://pim.dev/problems/agent-budget-exceeded',
                'title' => 'Estimated bulk cost exceeds the remaining daily budget.',
                'status' => Response::HTTP_TOO_MANY_REQUESTS,
                'estimated_cost_usd' => $estimate->estCostUsd,
                'day_budget_remaining_usd' => number_format($remaining, 6, '.', ''),
            ], Response::HTTP_TOO_MANY_REQUESTS, ['content-type' => 'application/problem+json']);
        }

        $batchId = Uuid::v7();
        $run = new AgentRun(
            $userId,
            AgentRunSurface::CmdK,
            \sprintf('Bulk content generation (%s) for %d products.', $toolName, \count($productIds)),
            ['bulk_content' => ['tool' => $toolName, 'product_count' => \count($productIds)]],
        );
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new BulkGenerateContentMessage(
            runId: $run->getId(),
            tenantId: $tenant->getId(),
            batchId: $batchId,
            productIds: $productIds,
            toolName: $toolName,
            recipeId: null !== $recipe ? $recipe->getId()->toRfc4122() : null,
            locale: $locale,
            channel: $channel,
        ));

        return new JsonResponse([
            'run_id' => $run->getId()->toRfc4122(),
            'pending_change_batch_id' => $batchId->toRfc4122(),
            'product_count' => \count($productIds),
            'estimate' => $this->serializeEstimate($estimate),
        ], Response::HTTP_ACCEPTED, ['Location' => \sprintf('/api/agent/runs/%s', $run->getId()->toRfc4122())]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function productCount(array $body): int
    {
        if (\is_int($body['product_count'] ?? null)) {
            return max(0, $body['product_count']);
        }

        return \count($this->productIds($body));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function productIds(array $body): array
    {
        $ids = $body['selected_ids'] ?? null;
        if (!\is_array($ids)) {
            return [];
        }

        $valid = [];
        foreach ($ids as $id) {
            if (\is_string($id) && Uuid::isValid($id)) {
                $valid[] = $id;
            }
        }

        return array_values(array_unique($valid));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{0: string, 1: ContentRecipe|null}
     */
    private function resolveMode(array $body): array
    {
        $mode = \is_string($body['mode'] ?? null) ? $body['mode'] : 'descriptions';
        $config = self::MODES[$mode] ?? self::MODES['descriptions'];

        $repository = $this->entityManager->getRepository(ContentRecipe::class);
        $recipeId = $body['recipe_id'] ?? null;
        if (\is_string($recipeId) && Uuid::isValid($recipeId)) {
            $recipe = $repository->find($recipeId);
            if ($recipe instanceof ContentRecipe) {
                return [$config['tool'], $recipe];
            }
        }

        $recipe = $repository->findOneBy(['code' => $config['recipe'], 'isBuiltIn' => true])
            ?? $repository->findOneBy(['code' => $config['recipe']]);

        return [$config['tool'], $recipe instanceof ContentRecipe ? $recipe : null];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEstimate(ContentCostEstimate $estimate): array
    {
        return [
            'product_count' => $estimate->productCount,
            'input_tokens_per_product' => $estimate->inputTokensPerProduct,
            'output_tokens_per_product' => $estimate->outputTokensPerProduct,
            'est_input_tokens' => $estimate->estInputTokens,
            'est_output_tokens' => $estimate->estOutputTokens,
            'est_cost_usd' => $estimate->estCostUsd,
            'model' => $estimate->model,
        ];
    }

    private function tenant(): Tenant
    {
        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new BadRequestHttpException('No tenant resolved for this request.');
        }

        return $tenant;
    }

    private function userId(): Uuid
    {
        $user = $this->security->getUser();
        if (!$user instanceof UserIdentityAware) {
            throw new BadRequestHttpException('The agent API requires a user principal (API keys are not user-scoped).');
        }

        return $user->getId();
    }
}
