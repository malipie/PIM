<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\UpdateCatalogObject;

use App\Catalog\Application\ObjectAttributesUpserter;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Channel\Contracts\ChannelResolverInterface;
use App\Shared\Domain\Tenant;
use App\Workflow\Contracts\ObjectEditorialWorkflow;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Workflow\WorkflowInterface;

#[AsMessageHandler]
final readonly class UpdateCatalogObjectHandler
{
    public function __construct(
        private CatalogObjectRepositoryInterface $catalogObjects,
        private ObjectAttributesUpserter $attributesUpserter,
        private ChannelResolverInterface $channels,
        #[Target(ObjectEditorialWorkflow::NAME)]
        private WorkflowInterface $objectEditorial,
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

        if (null !== $command->enabled) {
            $object->changeEnabled($command->enabled);
        }
        if (null !== $command->status && $command->status !== $object->getStatus()) {
            $this->applyStatusTransition($object, $command->status);
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

    /**
     * PATCH sends a target status, not a transition name — resolve it
     * against the `object_editorial` state machine (ADR-0029). Only
     * transitions enabled for the current marking qualify, so topology
     * (and, from WFL-P1-01 on, RBAC guards) is enforced on the legacy
     * PATCH surface too.
     */
    private function applyStatusTransition(CatalogObject $object, string $targetStatus): void
    {
        foreach ($this->objectEditorial->getEnabledTransitions($object) as $transition) {
            if (\in_array($targetStatus, $transition->getTos(), true)) {
                $this->objectEditorial->apply($object, $transition->getName());

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
