<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\CreateCatalogObject;

use App\Catalog\Application\ObjectAttributesUpserter;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectCategoryRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Domain\Tenant;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Handler for {@see CreateCatalogObjectCommand}.
 *
 * Resolves the `ObjectType`, validates kind invariants, instantiates the
 * aggregate (constructor only — no setters per RF "0 publicznych setterów"
 * lesson), optionally re-parents it, and persists. The new aggregate's id
 * is returned via Messenger's `HandledStamp` so the API processor can
 * read it back for the response shape.
 *
 * `parentId` is only resolved when present; we re-fetch the parent through
 * the repository so it is managed in the same EntityManager context as
 * the new aggregate before flush.
 */
#[AsMessageHandler]
final readonly class CreateCatalogObjectHandler
{
    public function __construct(
        private CatalogObjectRepositoryInterface $catalogObjects,
        private ObjectTypeRepositoryInterface $objectTypes,
        private ObjectAttributesUpserter $attributesUpserter,
        private ObjectCategoryRepositoryInterface $objectCategories,
        private \App\Catalog\Application\CategoryTreeAssignmentGuard $treeGuard,
    ) {
    }

    public function __invoke(CreateCatalogObjectCommand $command): Uuid
    {
        $objectType = $this->objectTypes->findById($command->objectTypeId);
        if (null === $objectType) {
            throw new NotFoundHttpException(\sprintf(
                'ObjectType "%s" was not found.',
                $command->objectTypeId->toRfc4122(),
            ));
        }

        if ($objectType->getKind() !== $command->expectedKind) {
            throw new UnprocessableEntityHttpException(\sprintf(
                'ObjectType kind "%s" does not match the operation kind "%s".',
                $objectType->getKind()->value,
                $command->expectedKind->value,
            ));
        }

        // #1415 — duplicate IDs used to surface as a raw 500 from the
        // (tenant, kind, code) unique constraint on the poly-kind create.
        // Pre-check for a clean 409; the DB index stays as the race-proof
        // backstop. Categories are skipped — their codes are unique per
        // tree, not per kind, and the constraint excludes them.
        $tenant = $objectType->getTenant();
        if (ObjectKind::Category !== $objectType->getKind()
            && $tenant instanceof Tenant
            && $this->catalogObjects->findByCode($command->code, $objectType->getKind(), $tenant) instanceof CatalogObject) {
            throw new ConflictHttpException(\sprintf(
                'ID "%s" is already used by another "%s" entry.',
                $command->code,
                $objectType->getCode(),
            ));
        }

        $object = new CatalogObject($objectType, $command->code);
        $parent = null;

        if (null !== $command->parentId) {
            $parent = $this->catalogObjects->findById($command->parentId);
            if (null === $parent) {
                throw new NotFoundHttpException(\sprintf(
                    'Parent CatalogObject "%s" was not found.',
                    $command->parentId->toRfc4122(),
                ));
            }
            $object->assignParent($parent);
        }

        // ADR-015 — a category belongs to exactly one categorizable
        // ObjectType's tree. Require the scope on create; when a parent is
        // given, the child must join the same tree as its parent (a subtree
        // cannot jump trees).
        if (ObjectKind::Category === $command->expectedKind) {
            if (null === $command->categoryTargetObjectTypeId) {
                throw new UnprocessableEntityHttpException(
                    '"categoryTargetObjectTypeId" is required when creating a category.',
                );
            }
            $targetType = $this->objectTypes->findById($command->categoryTargetObjectTypeId);
            if (null === $targetType) {
                throw new UnprocessableEntityHttpException(\sprintf(
                    'Target ObjectType "%s" was not found.',
                    $command->categoryTargetObjectTypeId->toRfc4122(),
                ));
            }
            if (!$targetType->isCategorizable()) {
                throw new UnprocessableEntityHttpException(\sprintf(
                    'ObjectType "%s" is not categorizable; enable it before creating its category tree.',
                    $targetType->getCode(),
                ));
            }
            if (null !== $parent) {
                $parentTarget = $parent->getCategoryTargetObjectType();
                if (null === $parentTarget || !$parentTarget->getId()->equals($targetType->getId())) {
                    throw new UnprocessableEntityHttpException(
                        'A child category must belong to the same ObjectType tree as its parent.',
                    );
                }
            }
            $object->scopeCategoryTo($targetType);
        }

        // VIEW-04 (#408) — derive the ltree `path` here rather than in a
        // Doctrine prePersist listener. ORM 3 freezes the insert
        // change-set before listeners run, so a `prePersist` write to
        // `path` does not reach the INSERT and the row lands with `path
        // = NULL` despite the in-memory aggregate carrying the value.
        // Setting it on the aggregate before `save()` keeps the change
        // inside the natural change-set computation.
        if (ObjectKind::Category === $command->expectedKind && null === $object->getPath()) {
            $code = $object->getCode();
            if (1 === preg_match('/^[a-z_][a-z0-9_]*$/', $code)) {
                $parentPath = $parent?->getPath();
                $object->attachToPath(
                    null !== $parentPath && '' !== $parentPath ? $parentPath.'.'.$code : $code,
                );
            }
        }

        $this->catalogObjects->save($object);

        if ([] !== $command->attributes) {
            $this->attributesUpserter->upsert($object, $command->attributes, $command->provenance);
        }

        // #891 / #1359 — atomic category assignment for any categorizable
        // ObjectType (products + custom categorizable kinds). Validate
        // every id resolves to a tenant-scoped `kind=category` BEFORE the
        // junction write so the row never lands partially populated.
        if ($object->getObjectType()->isCategorizable() && null !== $command->categoryIds) {
            if ([] === $command->categoryIds) {
                throw new UnprocessableEntityHttpException('"categoryIds" must contain at least one category.');
            }
            if (null === $command->primaryCategoryId) {
                throw new UnprocessableEntityHttpException('"primaryCategoryId" is required when "categoryIds" is non-empty.');
            }
            $primaryInList = false;
            foreach ($command->categoryIds as $categoryId) {
                if ($categoryId->equals($command->primaryCategoryId)) {
                    $primaryInList = true;
                    break;
                }
            }
            if (!$primaryInList) {
                throw new UnprocessableEntityHttpException('"primaryCategoryId" must appear in "categoryIds".');
            }
            foreach ($command->categoryIds as $categoryId) {
                $category = $this->catalogObjects->findById($categoryId);
                if (null === $category) {
                    throw new UnprocessableEntityHttpException(\sprintf(
                        'Category "%s" was not found.',
                        $categoryId->toRfc4122(),
                    ));
                }
                if (ObjectKind::Category !== $category->getKind()) {
                    throw new UnprocessableEntityHttpException(\sprintf(
                        'Object "%s" is not a category.',
                        $categoryId->toRfc4122(),
                    ));
                }
                // ADR-015 — the category must belong to this object's tree.
                $this->treeGuard->assertSameTree($object, $category);
            }
            $this->objectCategories->replaceForProduct($object, $command->categoryIds, $command->primaryCategoryId);
        }

        return $object->getId();
    }
}
