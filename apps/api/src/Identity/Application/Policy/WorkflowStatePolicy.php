<?php

declare(strict_types=1);

namespace App\Identity\Application\Policy;

use App\Identity\Application\PermissionResolverInterface;
use App\Identity\Domain\Entity\User;

/**
 * RBAC-P3-011 (#674) — per-workflow-state edit policy aligned with the
 * PRD §3.8 (forward-spec) role × state matrix.
 *
 * CatalogObject ships three statuses today (`draft`, `published`,
 * `archived`) — the `review` state mentioned in the ticket prospectively
 * lands with a dedicated Symfony Workflow definition later (epic 0.6
 * or follow-up). The policy maps onto the current three:
 *
 *   - **draft**       — editable by every role that holds
 *                       `products.edit` (Owner / Admin / Catalog Manager
 *                       / Marketing / Channel Manager per macierz),
 *   - **published**   — editable only by roles holding
 *                       `workflow.edit_any_state` (Owner / Admin per
 *                       PrdRoleTemplates). Anyone else needs the
 *                       *auto-unpublish* path: caller posts
 *                       `?auto_unpublish=true`, the policy reports
 *                       `requiresAutoUnpublish()=true`, and the controller
 *                       atomically transitions published → draft + edit +
 *                       audit_log special_flag `AUTO_UNPUBLISH_FOR_EDIT`,
 *   - **archived**    — locked, no edits in MVP (archived state is the
 *                       "soft-deleted" terminal status; restoration goes
 *                       through a separate transition endpoint).
 *
 * The policy returns booleans only — the actual auto-transition
 * (state mutation + atomic edit + audit entry) is the controller /
 * Symfony Workflow's responsibility once it lands. This keeps the
 * authorization layer free of side effects.
 *
 * Auto-transition permission code (`workflow.transition.unpublish`) is
 * not yet seeded in `PrdRoleTemplates` — the policy short-circuits to
 * `false` until the seeder adds it, so the auto-unpublish path is
 * effectively dormant until the macierz catches up. A focused
 * follow-up adds the code + UI toggle.
 */
final readonly class WorkflowStatePolicy
{
    public const string STATE_DRAFT = 'draft';
    public const string STATE_REVIEW = 'review';
    public const string STATE_PUBLISHED = 'published';
    public const string STATE_ARCHIVED = 'archived';

    public function __construct(private PermissionResolverInterface $resolver)
    {
    }

    /**
     * @param non-empty-string $state
     */
    public function canEditInState(User $user, string $state): bool
    {
        $permissions = $this->resolver->resolve($user);

        return match ($state) {
            self::STATE_DRAFT => $permissions->has('products.edit'),
            // WFL-P1-02 (#2416) — the review place shipped with the
            // object_editorial state machine (ADR-0029); editable only
            // for reviewers (edit_in_review) or the edit-anywhere grant.
            self::STATE_REVIEW => $permissions->has('workflow.edit_in_review')
                || $permissions->has('workflow.edit_any_state'),
            self::STATE_PUBLISHED => $permissions->has('workflow.edit_any_state'),
            self::STATE_ARCHIVED => false,
            default => false,
        };
    }

    /**
     * Indicates whether the caller could edit a `published` entity via
     * the auto-unpublish transition path (caller passes
     * `?auto_unpublish=true`).
     *
     * Returns true only when:
     *   - the entity is in `published` state,
     *   - the caller has `products.edit` (broad edit gate),
     *   - the caller does NOT have `workflow.edit_any_state` (otherwise
     *     they would edit-in-place without transition),
     *   - the caller has `workflow.transition.unpublish` (currently
     *     unseeded — see class-level note).
     */
    public function requiresAutoUnpublish(User $user, string $state): bool
    {
        if (self::STATE_PUBLISHED !== $state) {
            return false;
        }

        $permissions = $this->resolver->resolve($user);
        if (!$permissions->has('products.edit')) {
            return false;
        }
        if ($permissions->has('workflow.edit_any_state')) {
            return false;
        }

        return $permissions->has('workflow.transition.unpublish');
    }
}
