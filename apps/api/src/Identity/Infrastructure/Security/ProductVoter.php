<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;

/**
 * RBAC-P3-002 (#665) — per-product authorization aligned with the
 * PRD §3.2 macierz permission codes (`products.view`,
 * `products.bulk_operations`, `products.approve_pending_changes`, …).
 *
 * Subject discrimination: ADR-009 unified Product/Category/Asset into
 * the single {@see CatalogObject} entity with an {@see ObjectKind}
 * discriminator. This voter accepts only `kind=Product` instances;
 * the sibling voters (#666 CategoryVoter / AssetVoter) handle the
 * other built-in kinds. Class-level subjects (Post / GetCollection)
 * are gated by the FQCN match alone — the kind discriminator applies
 * on instances where collection scope is enforced by Doctrine filters.
 *
 * The Identity-Infrastructure → Catalog-Domain reference is allowed
 * by an explicit Deptrac baseline entry — voters legitimately need
 * to know the resource class they protect. Phase 6 may move the
 * Catalog domain enums into Catalog_Contracts; this voter follows.
 *
 * Per-attribute restrictions (#671 AttributePermissionPolicy),
 * locale/channel scope (#672 LocaleChannelScopePolicy), workflow-state
 * gating (#674 WorkflowStatePolicy) hook in via separate voters /
 * policies — this voter handles only the broad PRD §3.2 gate.
 */
final class ProductVoter extends AbstractPrdVoter
{
    /**
     * @return array<string, string>
     */
    protected function permissionMap(): array
    {
        return [
            'view' => 'products.view',
            'add' => 'products.add',
            'edit' => 'products.edit',
            // WFL-P1-02 (#2416) — alias for the API Platform Patch
            // operation attribute (`is_granted('UPDATE', object)`). The
            // PRD §3.2 matrix always intended products.edit to gate
            // product edits, but this voter only spoke lowercase verbs,
            // so PRD-only principals (marketing, catalog_manager) were
            // denied by default and the §3.8 state policy was dead code
            // on the PATCH surface. Remaining uppercase aliases
            // (READ/CREATE/DELETE) are audited in WFL-P6-01.
            'UPDATE' => 'products.edit',
            // WFL-P6-02 (#2435) — the products sugar path uses a
            // kind-specific attribute for creation because at POST time
            // the voter subject is the bare CatalogObject class string
            // (no instance, no kind). A generic 'CREATE' alias here
            // would leak products.add onto /api/categories and
            // /api/objects, which share that attribute — the same
            // escalation shape as GOLIVE #2129. CatalogObjectVoter
            // aliases CREATE_PRODUCT to legacy object.write so pre-PRD
            // principals keep working (affirmative strategy).
            'CREATE_PRODUCT' => 'products.add',
            // Same WFL-P6-02 story for reads: the item GET carries an
            // instance (kind-checkable) but the collection GET carries
            // the class string shared with the other kinds' lists, so
            // both ask for READ_PRODUCT instead of aliasing 'READ'.
            'READ_PRODUCT' => 'products.view',
            // WFL-P6-02 (#2435) — the admin detail page loads objects
            // through the poly-kind /api/objects/{id} item GET, which
            // asks for plain 'READ' with an instance subject. Instances
            // are kind-checked by acceptsSubject(), so this alias only
            // ever grants products; supports() below keeps this voter
            // out of class-string 'READ' (collection) decisions where
            // the kind is unknowable.
            'READ' => 'products.view',
            // #2881 — the DELETE counterpart of the two aliases above.
            // Categories and multimedia got theirs in #2852; products were
            // missed, and the gap stayed invisible because `object.delete`
            // happens to collide between the legacy grid (object × delete)
            // and the ULV-04a verb set, so the three roles carrying that
            // verb deleted products through the legacy voter by accident.
            // A custom role built in the panel with products.delete and
            // nothing else could not.
            'DELETE' => 'products.delete',
            'delete' => 'products.delete',
            'bulk_operations' => 'products.bulk_operations',
            'approve_pending_changes' => 'products.approve_pending_changes',
        ];
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // The shared uppercase attributes are every kind's gate — without
        // an instance there is no kind to check, so granting products.*
        // here would leak onto /api/objects and the other kinds'
        // surfaces. Abstain and leave those to the legacy
        // CatalogObjectVoter; surfaces that should follow the product
        // codes ask for READ_PRODUCT / CREATE_PRODUCT / CREATE_OBJECT
        // explicitly.
        if (\is_string($subject) && \in_array($attribute, ['READ', 'UPDATE', 'DELETE'], true)) {
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

        return ObjectKind::Product === $subject->getKind();
    }
}
