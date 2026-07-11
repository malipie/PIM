<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\UpdateCatalogObject;

use App\Catalog\Application\ObjectAttributesUpserter;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Channel\Contracts\ChannelResolverInterface;
use App\Identity\Contracts\Policy\WorkflowStateEditPolicyInterface;
use App\Shared\Domain\Tenant;
use App\Workflow\Contracts\EditorialWorkflowProviderInterface;
use App\Workflow\Contracts\ObjectEditorialWorkflow;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class UpdateCatalogObjectHandler
{
    public function __construct(
        private CatalogObjectRepositoryInterface $catalogObjects,
        private ObjectAttributesUpserter $attributesUpserter,
        private ChannelResolverInterface $channels,
        private EditorialWorkflowProviderInterface $workflows,
        private WorkflowStateEditPolicyInterface $statePolicy,
    ) {
    }

    public function __invoke(UpdateCatalogObjectCommand $command): void
    {
        $object = $this->catalogObjects->findById($command->id);
        if (null === $object) {
            throw new NotFoundHttpException(\sprintf(
                'CatalogObject "%s" was not found.',
                $command->id->toRfc4122(),
            ));
        }

        // MODR-10 (#932) — optimistic-lock guard. Pre-flight check against
        // the in-memory version covers the common case (a stale tab); the
        // Doctrine `@Version` flush below also throws OptimisticLockException
        // if a concurrent write slipped between our load + save.
        if (null !== $command->expectedVersion && $command->expectedVersion !== $object->getVersion()) {
            throw new ConflictHttpException(\sprintf(
                'CatalogObject "%s" was modified by another user (expected v%d, current v%d). Refresh and try again.',
                $command->id->toRfc4122(),
                $command->expectedVersion,
                $object->getVersion(),
            ));
        }

        // Status transition first (guards enforce who may move the
        // object); content edits below are then judged against the NEW
        // state — "reject + fix in one PATCH" works for a reviewer.
        if (null !== $command->status && $command->status !== $object->getStatus()) {
            $this->applyStatusTransition($object, $command->status);
        }

        // WFL-P1-02/P1-03 (PRD §3.8) — the permission matrix says whether
        // the caller may edit AT ALL; the workflow state says whether they
        // may edit NOW. Published is editable in place only with
        // workflow.edit_any_state; holders of workflow.transition.unpublish
        // get the atomic auto-unpublish path; archived is read-only.
        if ($this->hasContentEdits($command)) {
            $state = $object->getStatus();
            if (!$this->statePolicy->canEditInState($state)) {
                if ($this->statePolicy->requiresAutoUnpublish($state)) {
                    // Same flush as the edit below — transition + content
                    // change land atomically; the transition-log row carries
                    // the audit flag (PRD: AUTO_UNPUBLISH_FOR_EDIT).
                    $this->workflows->for($object, $object->getObjectType()->getId()->toRfc4122())
                        ->apply($object, ObjectEditorialWorkflow::TRANSITION_UNPUBLISH, [
                            'auto_unpublish' => true,
                            'special_flag' => 'AUTO_UNPUBLISH_FOR_EDIT',
                        ]);
                } else {
                    throw new AccessDeniedHttpException(\sprintf(
                        'workflow_state_locked: object is "%s" — content edits are blocked by the workflow state policy.%s',
                        $state,
                        CatalogObject::STATUS_PUBLISHED === $state ? ' request_unpublish_available' : '',
                    ));
                }
            }
        }

        if (null !== $command->enabled) {
            $object->changeEnabled($command->enabled);
        }

        if ($command->clearParent) {
            $object->assignParent(null);
        } elseif (null !== $command->parentId) {
            $parent = $this->catalogObjects->findById($command->parentId);
            if (null === $parent) {
                throw new NotFoundHttpException(\sprintf(
                    'Parent CatalogObject "%s" was not found.',
                    $command->parentId->toRfc4122(),
                ));
            }
            $object->assignParent($parent);
        }

        if ($command->clearPath) {
            $object->attachToPath(null);
        } elseif (null !== $command->path) {
            $object->attachToPath($command->path);
        }

        try {
            $this->catalogObjects->save($object);
        } catch (OptimisticLockException $e) {
            throw new ConflictHttpException(\sprintf(
                'CatalogObject "%s" was modified by another user during save. Refresh and try again.',
                $command->id->toRfc4122(),
            ), $e);
        }

        if (null !== $command->attributes && [] !== $command->attributes) {
            $tenant = $object->getTenant();
            if (null !== $command->locale
                && $tenant instanceof Tenant
                && !$tenant->isLocaleEnabled($command->locale)) {
                throw new UnprocessableEntityHttpException(\sprintf(
                    'Locale "%s" is not enabled for this tenant.',
                    $command->locale,
                ));
            }

            $channelId = null;
            if (null !== $command->channel && $tenant instanceof Tenant) {
                $channelId = $this->channels->resolveId($command->channel, $tenant);
                if (null === $channelId) {
                    throw new UnprocessableEntityHttpException(\sprintf(
                        'Channel "%s" was not found for this tenant.',
                        $command->channel,
                    ));
                }
            }

            $this->attributesUpserter->upsert(
                $object,
                $command->attributes,
                locale: $command->locale,
                channelId: $channelId,
            );
        }
    }

    private function hasContentEdits(UpdateCatalogObjectCommand $command): bool
    {
        return null !== $command->enabled
            || $command->clearParent
            || null !== $command->parentId
            || $command->clearPath
            || null !== $command->path
            || (null !== $command->attributes && [] !== $command->attributes);
    }

    /**
     * PATCH sends a target status, not a transition name — resolve it
     * against the `object_editorial` state machine (ADR-0029). Only
     * transitions enabled for the current marking qualify, so topology
     * (and, from WFL-P1-01 on, RBAC guards) is enforced on the legacy
     * PATCH surface too.
     */
    private function applyStatusTransition(CatalogObject $object, string $targetStatus): void
    {
        $workflow = $this->workflows->for($object, $object->getObjectType()->getId()->toRfc4122());
        foreach ($workflow->getEnabledTransitions($object) as $transition) {
            if (\in_array($targetStatus, $transition->getTos(), true)) {
                $workflow->apply($object, $transition->getName());

                return;
            }
        }

        throw new ConflictHttpException(\sprintf(
            'Status transition "%s" -> "%s" is not allowed by the "%s" workflow.',
            $object->getStatus(),
            $targetStatus,
            ObjectEditorialWorkflow::NAME,
        ));
    }
}
