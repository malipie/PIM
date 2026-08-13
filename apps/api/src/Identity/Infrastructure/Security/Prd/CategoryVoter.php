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

            // #2838 — API Platform asks in uppercase; without these the
            // legacy voter was the only one voting and it demands
            // `category.read`, which no PRD role holds.
            'READ' => 'categories.view',
            'CREATE' => 'categories.add_edit',
            'UPDATE' => 'categories.add_edit',
            'WRITE' => 'categories.add_edit',
            'DELETE' => 'categories.delete',
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
