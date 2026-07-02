<?php

declare(strict_types=1);

namespace App\Agent\Presentation\Controller;

use App\Agent\Application\AgentFeatureGuard;
use App\Agent\Application\Approval\AgentApprovalService;
use App\Agent\Application\Run\AgentRunStarter;
use App\Agent\Application\Run\AgentTurnService;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentMessage;
use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Domain\Entity\AgentToolCall;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeView;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use App\Shared\Application\UserIdentityAware;
use App\Shared\Domain\Tenant;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P4-01 (#1968) — the public agent API (PRD §9.2): the admin chat
 * and Cmd+K use exactly these endpoints, and so do integrators (ADR-0020
 * "API is the product"). Custom #[Route] controllers per ADR-0012
 * (procedural CQRS surface), auto-documented into OpenAPI by
 * CustomRouteOpenApiFactory; errors are RFC 7807 problem documents via
 * the shared listener.
 *
 * Authorization model:
 *   - agent.bulk_actions   — own the conversation: start runs, send
 *                            turns, cancel, read own runs/history;
 *   - agent.approve_pending — decide: approve / reject / rollback.
 * Ownership scoping: reads and turn/cancel are limited to the caller's
 * own runs (someone else's run id answers 404, not 403 — no oracle);
 * decisions are permission-scoped (a manager may approve a run they do
 * not own). Tenant isolation rides on the Doctrine TenantFilter + RLS.
 */
final readonly class AgentRunController
{
    public function __construct(
        private AgentFeatureGuard $featureGuard,
        private AgentRunStarter $starter,
        private AgentTurnService $turns,
        private AgentApprovalService $approval,
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
        private Security $security,
        private PendingChangesPort $pendingChanges,
    ) {
    }

    #[Route('/api/agent/runs', name: 'pim_agent_run_start', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'agent', action: 'bulk_actions')]
    public function start(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        $this->featureGuard->assertEnabled($tenant);

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $intent = $body['intent'] ?? null;
        if (!\is_string($intent) || '' === trim($intent)) {
            throw new BadRequestHttpException('intent must be a non-empty string.');
        }

        $surfaceRaw = $body['surface'] ?? AgentRunSurface::Chat->value;
        $surface = \is_string($surfaceRaw) ? AgentRunSurface::tryFrom($surfaceRaw) : null;
        if (!$surface instanceof AgentRunSurface) {
            throw new BadRequestHttpException('surface must be one of: chat, cmdk.');
        }

        /** @var array<string, mixed> $context */
        $context = \is_array($body['context'] ?? null) ? $body['context'] : [];

        $run = $this->starter->start($tenant, $this->userId(), $surface, trim($intent), $context);

        // With synchronous transports (tests) the loop already ran and its
        // final clear() detached $run — re-read the fresh state; with the
        // async queue this is a no-op re-read of "planning".
        $run = $this->anyRun($run->getId()->toRfc4122());

        return new JsonResponse(
            data: $this->serializeSummary($run),
            status: Response::HTTP_CREATED,
            headers: ['Location' => \sprintf('/api/agent/runs/%s', $run->getId()->toRfc4122())],
        );
    }

    #[Route('/api/agent/runs', name: 'pim_agent_run_history', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'agent', action: 'bulk_actions')]
    public function history(Request $request): JsonResponse
    {
        $this->featureGuard->assertEnabled($this->tenant());

        $page = max(1, $request->query->getInt('page', 1));
        $perPage = min(100, max(1, $request->query->getInt('per_page', 20)));

        $runs = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(AgentRun::class, 'r')
            ->where('r.userId = :user')
            ->setParameter('user', $this->userId(), 'uuid')
            ->orderBy('r.startedAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        /* @var list<AgentRun> $runs */
        return new JsonResponse([
            'items' => array_map($this->serializeSummary(...), $runs),
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    #[Route('/api/agent/runs/{id}', name: 'pim_agent_run_detail', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'agent', action: 'bulk_actions')]
    public function detail(string $id): JsonResponse
    {
        $this->featureGuard->assertEnabled($this->tenant());
        $run = $this->ownRun($id);

        $messages = $this->entityManager->getRepository(AgentMessage::class)
            ->findBy(['run' => $run], ['id' => 'ASC']);
        $toolCalls = $this->entityManager->getRepository(AgentToolCall::class)
            ->findBy(['run' => $run], ['id' => 'ASC']);

        return new JsonResponse($this->serializeSummary($run) + [
            'context' => $run->getContext(),
            'error_message' => $run->getErrorMessage(),
            'messages' => array_map(static fn (AgentMessage $m): array => [
                'id' => $m->getId()->toRfc4122(),
                'role' => $m->getRole(),
                'content' => $m->getContent(),
                'created_at' => $m->getCreatedAt()->format(DateTimeInterface::ATOM),
            ], $messages),
            'tool_calls' => array_map(static fn (AgentToolCall $c): array => [
                'id' => $c->getId()->toRfc4122(),
                'tool' => $c->getToolName(),
                'kind' => $c->getKind(),
                'arguments' => $c->getArguments(),
                'result_summary' => $c->getResultSummary(),
                'duration_ms' => $c->getDurationMs(),
                'created_at' => $c->getCreatedAt()->format(DateTimeInterface::ATOM),
            ], $toolCalls),
        ]);
    }

    #[Route('/api/agent/runs/{id}/messages', name: 'pim_agent_run_message', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'agent', action: 'bulk_actions')]
    public function message(string $id, Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        $this->featureGuard->assertEnabled($tenant);
        $run = $this->ownRun($id);

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];
        $message = $body['message'] ?? null;
        if (!\is_string($message) || '' === trim($message)) {
            throw new BadRequestHttpException('message must be a non-empty string.');
        }

        $this->turns->appendUserMessage($run, $tenant, trim($message));

        return new JsonResponse($this->serializeSummary($run), Response::HTTP_ACCEPTED);
    }

    /**
     * AGENT-P6-03 (#1976) — the operator-facing PLAN: the materialized
     * batch rows (before -> after diffs) behind the run awaiting
     * approval. Permission-scoped like the decisions (a manager reviews
     * runs they do not own).
     */
    #[Route('/api/agent/runs/{id}/plan', name: 'pim_agent_run_plan', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'agent', action: 'approve_pending')]
    public function plan(string $id, Request $request): JsonResponse
    {
        $this->featureGuard->assertEnabled($this->tenant());
        $run = $this->anyRun($id);

        $batchId = $run->getPendingChangeBatchId();
        if (null === $batchId) {
            return new JsonResponse(['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 50]);
        }

        $page = max(1, $request->query->getInt('page', 1));
        $perPage = min(200, max(1, $request->query->getInt('per_page', 50)));

        $rows = $this->pendingChanges->listBatch($batchId, $perPage, ($page - 1) * $perPage);

        return new JsonResponse([
            'items' => array_map(static fn (PendingChangeView $row): array => [
                'id' => $row->id->toRfc4122(),
                'change_type' => $row->changeType->value,
                'status' => $row->status->value,
                'target_object_id' => $row->targetObjectId?->toRfc4122(),
                'attribute_code' => $row->attributeCode,
                'scope_locale' => $row->scopeLocale,
                'scope_channel' => $row->scopeChannel,
                'before' => $row->before,
                'after' => $row->after,
                'provenance' => $row->provenance,
            ], $rows),
            'total' => $this->pendingChanges->countBatch($batchId),
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    #[Route('/api/agent/runs/{id}/approve', name: 'pim_agent_run_approve', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'agent', action: 'approve_pending')]
    public function approve(string $id): JsonResponse
    {
        $this->featureGuard->assertEnabled($this->tenant());
        $run = $this->anyRun($id);

        $approved = $this->approval->approve($run->getId(), $this->userId());

        return new JsonResponse($this->serializeSummary($approved));
    }

    #[Route('/api/agent/runs/{id}/reject', name: 'pim_agent_run_reject', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'agent', action: 'approve_pending')]
    public function reject(string $id): JsonResponse
    {
        $this->featureGuard->assertEnabled($this->tenant());
        $run = $this->anyRun($id);

        return new JsonResponse($this->serializeSummary($this->approval->reject($run->getId())));
    }

    #[Route('/api/agent/runs/{id}/rollback', name: 'pim_agent_run_rollback', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'agent', action: 'approve_pending')]
    public function rollback(string $id): JsonResponse
    {
        $this->featureGuard->assertEnabled($this->tenant());
        $run = $this->anyRun($id);

        return new JsonResponse($this->serializeSummary($this->approval->rollback($run->getId())));
    }

    #[Route('/api/agent/runs/{id}/cancel', name: 'pim_agent_run_cancel', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'agent', action: 'bulk_actions')]
    public function cancel(string $id): JsonResponse
    {
        $this->featureGuard->assertEnabled($this->tenant());
        $run = $this->ownRun($id);

        return new JsonResponse($this->serializeSummary($this->approval->cancel($run->getId())));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSummary(AgentRun $run): array
    {
        return [
            'id' => $run->getId()->toRfc4122(),
            'status' => $run->getStatus()->value,
            'surface' => $run->getSurface()->value,
            'intent' => $run->getIntent(),
            'model' => $run->getModel(),
            'pending_change_batch_id' => $run->getPendingChangeBatchId()?->toRfc4122(),
            'affected_count' => $run->getAffectedCount(),
            'bulk_operation_id' => $run->getBulkOperationId()?->toRfc4122(),
            'tokens_input' => $run->getTokensInput(),
            'tokens_output' => $run->getTokensOutput(),
            'cost_usd' => $run->getCostUsd(),
            'approved_by' => $run->getApprovedBy()?->toRfc4122(),
            'approved_at' => $run->getApprovedAt()?->format(DateTimeInterface::ATOM),
            'started_at' => $run->getStartedAt()->format(DateTimeInterface::ATOM),
            'completed_at' => $run->getCompletedAt()?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Own-run lookup: someone else's run answers 404 (no oracle).
     */
    private function ownRun(string $id): AgentRun
    {
        $run = $this->anyRun($id);
        if (!$run->getUserId()->equals($this->userId())) {
            throw new NotFoundHttpException('Agent run not found.');
        }

        return $run;
    }

    private function anyRun(string $id): AgentRun
    {
        $run = $this->entityManager->find(AgentRun::class, Uuid::fromString($id));
        if (!$run instanceof AgentRun) {
            throw new NotFoundHttpException('Agent run not found.');
        }

        return $run;
    }

    private function tenant(): Tenant
    {
        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new NotFoundHttpException('No tenant resolved for this request.');
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
