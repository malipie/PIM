<?php

declare(strict_types=1);

namespace App\Identity\Contracts\Policy;

/**
 * WFL-P1-02 (#2416) — cross-context seam for the PRD §3.8 workflow-state
 * edit policy: "the matrix says whether you may edit AT ALL, the state
 * says whether you may edit NOW". Catalog write paths (PATCH handler,
 * bulk actions, bulk edit) consult it before touching a row; the
 * Identity adapter resolves the current principal from the security
 * token — same current-token shape as {@see AttributePermissionReader}.
 *
 * Both methods treat a missing principal as a trusted system path
 * (import/CLI/fixtures write regardless of editorial state, ADR-0029
 * pillar 8); HTTP callers always carry a user because every write
 * endpoint requires authentication.
 */
interface WorkflowStateEditPolicyInterface
{
    /**
     * May the current principal edit entity CONTENT while the entity
     * sits in `$state`? (draft -> products.edit · review ->
     * workflow.edit_in_review · published -> workflow.edit_any_state ·
     * archived -> nobody.)
     */
    public function canEditInState(string $state): bool;

    /**
     * PRD §3.8 auto-unpublish: the current principal cannot edit
     * published in place (no edit_any_state) but holds
     * `workflow.transition.unpublish` — the write path may atomically
     * apply `unpublish` and proceed with the edit in one request
     * (audited via the transition-log context flag).
     */
    public function requiresAutoUnpublish(string $state): bool;
}
