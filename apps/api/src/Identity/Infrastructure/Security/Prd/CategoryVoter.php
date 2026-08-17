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

            // #2845 — kind-specific attributes for the two operations that
            // carry no instance. `/categories` and the poly-kind `/objects`
            // both hand the voter the bare CatalogObject class string, so a
            // plain `READ` / `CREATE` alias here would open the generic list
            // to anyone holding categories.view — the escalation
            // ProductCreatePermissionApiTest guards, and the reason #2838
            // left these out. Products solved it the same way in #2416.
            // #2881 — the create form REQUIRES a category (#891) and reads
            // this collection to offer one, so a role allowed to create a
            // categorisable object must be able to read the list or its
            // grant is unusable: the picker hangs on "Ładowanie…" and the
            // form refuses to save. Measured with a role holding only
            // products.add + products.view — the one the operator built.
            //
            // This is the read half only; `CREATE_CATEGORY` below still
            // needs categories.add_edit, so nobody gains the ability to
            // create or change a category by being allowed to create a
            // product.
            'READ_CATEGORY' => [
                'categories.view',
                'products.add',
                'multimedia.add_edit_own',
                'multimedia.add_edit_any',
                'object.add',
            ],
            'CREATE_CATEGORY' => 'categories.add_edit',

            // Instance-carrying operations are safe on the shared attribute:
            // acceptsSubject() below checks the kind, and supports() keeps
            // this voter out of the class-string decisions above.
            'READ' => 'categories.view',
            'UPDATE' => 'categories.add_edit',
            'DELETE' => 'categories.delete',
        ];
    }

    /**
     * Class-string subjects are shared by every kind's collection / POST, so
     * the kind cannot be checked. Those decisions belong to the explicit
     * READ_CATEGORY / CREATE_CATEGORY attributes; abstain here and let the
     * legacy CatalogObjectVoter answer for pre-PRD principals.
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        if (\is_string($subject) && \in_array($attribute, ['READ', 'CREATE', 'UPDATE', 'DELETE'], true)) {
            return false;
        }

        return parent::supports($attribute, $subject);
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
