<?php

declare(strict_types=1);

namespace App\Workflow\Domain\Repository;

use App\Workflow\Domain\Entity\WorkflowTransition;

/**
 * WFL-P0-04 (#2413) — write side of the transition log, used only
 * inside the Workflow BC (the completed-event subscriber). Reads for
 * other contexts go through {@see \App\Workflow\Contracts\TransitionLogPort}.
 */
interface WorkflowTransitionRepositoryInterface
{
    /**
     * Persist-only (no flush): the log row rides the flush of the write
     * path that applied the transition (PATCH handler save, transition
     * endpoint save, bulk batch flush), so object + log land together.
     */
    public function add(WorkflowTransition $transition): void;
}
