<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security\Prd;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Identity\Infrastructure\Security\AbstractPrdVoter;

/**
 * RBAC-P3-003 (#666) — per-category authorization aligned with the
 * PRD §3.2 macierz permission codes (`categories.view`,
 * `categories.add_edit`, `categories.delete`).
 *
 * Subject discrimination: per ADR-009 the unified {@see CatalogObject}
 * entity carries every kind; this voter accepts only `kind=Category`
 * instances. The product voter (#665) covers `kind=Product`, the asset
 * voter (sibling in this ticket) covers `kind=Asset`. Class-level
 * subjects pass through because collection scope is enforced by Doctrine
 * filters.
 *
 * The legacy {@see \App\Identity\Infrastructure\Security\CatalogObjectVoter}
 * still serves the `READ`/`WRITE`/`DELETE` uppercase-attribute style used
 * by the legacy XML ApiResource configs; this voter introduces the
 * PRD-aligned lowercase actions and runs alongside until the Phase 6
 * retrofit (RBAC-P6 backlog) swaps the API surface over.
 */
final class CategoryVoter extends AbstractPrdVoter
{
    /**
     * @return array<string, string|list<string>>
     */
    protected function permissionMap(): array
    {
        return [
            'view' => 'categories.view',
            'add_edit' => 'categories.add_edit',
            'delete' => 'categories.delete',

            // #2838 — NO uppercase aliases here, deliberately. This voter
            // answers for `CatalogObject`, and `/categories`, `/assets` and
            // the poly-kind `/objects` all ask the identical question
            // (`is_granted('READ', CatalogObject::class)`): on a collection
            // the kind is unknowable, so an alias here would open the
            // generic /api/objects list to anyone holding categories.view.
            // ProductVoter avoids this with kind-specific attributes
            // (READ_PRODUCT / CREATE_PRODUCT, #2416); giving categories and
            // assets the same treatment is tracked separately.
        ];
    }

    protected function subjectClass(): string
    {
        return CatalogObject::class;
    }

    protected function acceptsSubject(mixed $subject): bool
    {
        if (\is_string($subject)) {
            return CatalogObject::class === $subject;
        }

        if (!$subject instanceof CatalogObject) {
            return false;
        }

        return ObjectKind::Category === $subject->getKind();
    }
}
