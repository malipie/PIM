<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Workflow\Contracts\ObjectEditorialWorkflow;
use App\Workflow\Contracts\TransitionLogEntry;
use App\Workflow\Contracts\TransitionLogPort;
use DateTimeInterface;
use JsonException;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

use const JSON_THROW_ON_ERROR;

/**
 * WFL-P0-05 (#2414) — procedural workflow surface for catalog objects
 * (CQRS custom routes, ADR-0012/ADR-0029). Lives in Catalog because the
 * subject is the Catalog entity (marking on `objects.status`); the
 * transition log is read through the Workflow seam.
 *
 * Discovery (`GET …/workflow`) returns every transition applicable from
 * the current place with an `enabled` flag + blocker list, so the admin
 * FE renders guard-aware buttons without duplicating workflow logic
 * (WFL-P3-01). Blockers are topology-only until the RBAC guard map
 * lands in WFL-P1-01.
 */
final class ObjectWorkflowController
{
    private const string UUID_REQUIREMENT = '[0-9a-fA-F-]{36}';
    private const int COMMENT_MAX_LENGTH = 2000;
    private const int LOG_DEFAULT_LIMIT = 20;
    private const int LOG_MAX_LIMIT = 100;

    public function __construct(
        private readonly CatalogObjectRepositoryInterface $objects,
        private readonly TransitionLogPort $transitionLog,
        #[Target(ObjectEditorialWorkflow::NAME)]
        private readonly WorkflowInterface $objectEditorial,
    ) {
    }

    #[Route(
        path: '/api/objects/{id}/workflow',
        name: 'object_workflow_state',
        requirements: ['id' => self::UUID_REQUIREMENT],
        methods: ['GET'],
    )]
    #[RequiresPermission(module: 'workflow', action: 'view')]
    public function state(string $id): JsonResponse
    {
        $object = $this->loadObject($id);

        return new JsonResponse([
            'object_id' => $object->getId()->toRfc4122(),
            'workflow' => ObjectEditorialWorkflow::NAME,
            'current_place' => $object->getStatus(),
            'transitions' => $this->describeTransitions($object),
        ]);
    }

    #[Route(
        path: '/api/objects/{id}/workflow/transitions/{transition}',
        name: 'object_workflow_apply',
        requirements: ['id' => self::UUID_REQUIREMENT, 'transition' => '[a-z_]+'],
        methods: ['POST'],
    )]
    // Entry-level gate only — the per-transition RBAC permission map
    // (WFL-P1-01: approve -> workflow.approve_reject etc.) is enforced
    // inside can()/apply() by TransitionPermissionGuard and reported
    // back as 409 blockers carrying the missing permission code.
    #[RequiresPermission(module: 'workflow', action: 'view')]
    public function apply(string $id, string $transition, Request $request): JsonResponse
    {
        if (!\in_array($transition, ObjectEditorialWorkflow::TRANSITIONS, true)) {
            throw new NotFoundHttpException(\sprintf('Unknown workflow transition "%s".', $transition));
        }

        $object = $this->loadObject($id);
        $comment = $this->extractComment($request);

        if (!$this->objectEditorial->can($object, $transition)) {
            $blockers = $this->blockerMessages($object, $transition);

            throw new ConflictHttpException(\sprintf(
                'Transition "%s" is not allowed from "%s"%s',
                $transition,
                $object->getStatus(),
                [] === $blockers ? '.' : ': '.\implode(' ', $blockers),
            ));
        }

        $context = null === $comment ? [] : ['comment' => $comment];
        $this->objectEditorial->apply($object, $transition, $context);

        // Flushes the marking change together with the log row persisted
        // by TransitionLoggingSubscriber (persist-only, ADR-0029).
        $this->objects->save($object);

        return new JsonResponse([
            'object_id' => $object->getId()->toRfc4122(),
            'applied' => $transition,
            'current_place' => $object->getStatus(),
        ]);
    }

    #[Route(
        path: '/api/objects/{id}/workflow/transitions',
        name: 'object_workflow_transition_log',
        requirements: ['id' => self::UUID_REQUIREMENT],
        methods: ['GET'],
    )]
    #[RequiresPermission(module: 'workflow', action: 'view')]
    public function transitionLog(string $id, Request $request): JsonResponse
    {
        $object = $this->loadObject($id);

        $limit = \min(self::LOG_MAX_LIMIT, \max(1, $request->query->getInt('limit', self::LOG_DEFAULT_LIMIT)));
        $before = null;
        $beforeRaw = $request->query->getString('before');
        if ('' !== $beforeRaw) {
            if (!Uuid::isValid($beforeRaw)) {
                throw new UnprocessableEntityHttpException('The "before" cursor must be a transition id (UUID).');
            }
            $before = Uuid::fromString($beforeRaw);
        }

        // Fetch one extra row to decide whether another page exists.
        $entries = $this->transitionLog->pageForObject($object->getId(), $limit + 1, $before);
        $hasMore = \count($entries) > $limit;
        $entries = \array_slice($entries, 0, $limit);

        return new JsonResponse([
            'object_id' => $object->getId()->toRfc4122(),
            'items' => \array_map(self::serializeEntry(...), $entries),
            'next_cursor' => $hasMore && [] !== $entries
                ? $entries[\count($entries) - 1]->id->toRfc4122()
                : null,
        ]);
    }

    private function loadObject(string $id): CatalogObject
    {
        // TenantFilter scopes the lookup — a cross-tenant id resolves to
        // null and surfaces as 404, never a 403 information leak.
        $object = $this->objects->findById(Uuid::fromString($id));
        if (null === $object) {
            throw new NotFoundHttpException(\sprintf('CatalogObject "%s" was not found.', $id));
        }

        return $object;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function describeTransitions(CatalogObject $object): array
    {
        $described = [];
        foreach ($this->objectEditorial->getDefinition()->getTransitions() as $transition) {
            if (!\in_array($object->getStatus(), $transition->getFroms(), true)) {
                continue;
            }

            $blockers = [];
            foreach ($this->objectEditorial->buildTransitionBlockerList($object, $transition->getName()) as $blocker) {
                $blockers[] = ['code' => $blocker->getCode(), 'message' => $blocker->getMessage()];
            }

            $described[] = [
                'name' => $transition->getName(),
                'to' => \implode(',', \array_filter($transition->getTos(), \is_string(...))),
                'enabled' => [] === $blockers,
                'blockers' => $blockers,
            ];
        }

        return $described;
    }

    /**
     * @return list<string>
     */
    private function blockerMessages(CatalogObject $object, string $transition): array
    {
        $messages = [];
        foreach ($this->objectEditorial->buildTransitionBlockerList($object, $transition) as $blocker) {
            $messages[] = $blocker->getMessage();
        }

        return $messages;
    }

    private function extractComment(Request $request): ?string
    {
        $content = $request->getContent();
        if ('' === $content) {
            return null;
        }

        try {
            $payload = \json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnprocessableEntityHttpException('Request body must be valid JSON.');
        }

        if (!\is_array($payload)) {
            throw new UnprocessableEntityHttpException('Request body must be a JSON object.');
        }

        $comment = $payload['comment'] ?? null;
        if (null === $comment) {
            return null;
        }
        if (!\is_string($comment)) {
            throw new UnprocessableEntityHttpException('The "comment" field must be a string.');
        }

        $comment = \trim($comment);
        if ('' === $comment) {
            return null;
        }
        if (\mb_strlen($comment) > self::COMMENT_MAX_LENGTH) {
            throw new UnprocessableEntityHttpException(\sprintf(
                'The "comment" field must not exceed %d characters.',
                self::COMMENT_MAX_LENGTH,
            ));
        }

        return $comment;
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeEntry(TransitionLogEntry $entry): array
    {
        return [
            'id' => $entry->id->toRfc4122(),
            'transition' => $entry->transition,
            'from' => $entry->fromPlace,
            'to' => $entry->toPlace,
            'actor_user_id' => $entry->actorUserId?->toRfc4122(),
            'comment' => $entry->comment,
            'context' => $entry->context,
            'created_at' => $entry->createdAt->format(DateTimeInterface::ATOM),
        ];
    }
}
