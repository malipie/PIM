<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\DeleteAttribute;

use App\Catalog\Application\OrphanedAttributeValuePurger;
use App\Catalog\Application\Query\Usage\UsageQueryService;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * VIEW-02 (#374) — deletes an Attribute. The delete trigger lives on the
 * attribute detail page (`show.tsx`); the trash button is only rendered for
 * non-system rows but the BE guards below are the source of truth.
 *
 * Guards:
 *   - System attributes (`is_system=true`) are immutable per UI-08.3 (#258) → 422.
 *   - An attribute still REACHABLE in the model — attached to an ObjectType,
 *     an AttributeGroup, or distributed via a Category overlay — is non-deletable
 *     → 409. The supported path is to detach / remove it from the group first
 *     (both reachable in the modeling UI).
 *
 * Orphaned values: an attribute detached from EVERYTHING but still carrying
 * `object_values` is a dead-end — detaching removes it from the editor, so its
 * values can never be cleared through the UI, yet `object_values.attribute_id`
 * is ON DELETE RESTRICT and blocks the delete forever. When the attribute is
 * unreachable we therefore cascade-delete those orphaned values (rebuilding the
 * denormalised cache + queuing a reindex) via {@see OrphanedAttributeValuePurger}.
 *
 * Cascade behavior: `attribute_options.attribute_id` and
 * `attribute_group_attributes.attribute_id` are ON DELETE CASCADE, so options
 * and group memberships vanish with the attribute and never block deletion.
 */
#[AsMessageHandler]
final readonly class DeleteAttributeHandler
{
    public function __construct(
        private AttributeRepositoryInterface $repository,
        private UsageQueryService $usageQueryService,
        private OrphanedAttributeValuePurger $orphanedValuePurger,
    ) {
    }

    public function __invoke(DeleteAttributeCommand $command): void
    {
        $attribute = $this->repository->findById($command->id);
        if (null === $attribute) {
            throw new NotFoundHttpException(\sprintf(
                'Attribute "%s" was not found.',
                $command->id->toRfc4122(),
            ));
        }

        if ($attribute->isSystem()) {
            throw new UnprocessableEntityHttpException(\sprintf(
                'System attribute "%s" cannot be deleted.',
                $attribute->getCode(),
            ));
        }

        $usage = $this->usageQueryService->forAttributeFresh($attribute);
        $objectTypeCount = \count($usage['objectTypes']);
        $groupCount = \count($usage['groups']);
        $categoryCount = \count($usage['categories']);

        // Reachable anywhere in the model → must be detached / removed from the
        // group first (both doable in the UI). Values, if any, stay reachable.
        if ($objectTypeCount > 0 || $groupCount > 0 || $categoryCount > 0) {
            throw $this->inUseConflict($attribute->getCode(), $objectTypeCount, $groupCount, $categoryCount);
        }

        // Detached from everything but still has values → orphaned, unreachable
        // in any form. Cascade-delete them with the attribute.
        if ($usage['instanceCount'] > 0) {
            $this->orphanedValuePurger->purgeAndDelete($attribute);

            return;
        }

        try {
            $this->repository->remove($attribute);
        } catch (ForeignKeyConstraintViolationException) {
            // Safety-net: a new attachment/value may have been created between
            // the fresh pre-check and remove.
            throw $this->inUseConflict($attribute->getCode(), $objectTypeCount, $groupCount, $categoryCount);
        }
    }

    private function inUseConflict(string $code, int $objectTypeCount, int $groupCount, int $categoryCount): ConflictHttpException
    {
        return new ConflictHttpException(\sprintf(
            'Attribute "%s" is attached to %d object type(s), %d group(s) and %d categor(y/ies); detach or remove it from the group before deleting.',
            $code,
            $objectTypeCount,
            $groupCount,
            $categoryCount,
        ));
    }
}
