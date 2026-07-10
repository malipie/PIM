<?php

declare(strict_types=1);

namespace App\Workflow\Contracts;

use Symfony\Component\Uid\Uuid;

/**
 * WFL-P0-04 (#2413) — cross-BC read seam over the `workflow_transitions`
 * log. Writing happens exclusively inside the Workflow BC (the
 * `workflow.*.completed` subscriber); consumers in other contexts read
 * history through this port — the timeline endpoint (WFL-P0-05), the
 * review queue metadata ("who submitted", WFL-P3-02) and task
 * automation (WFL-P4-02).
 */
interface TransitionLogPort
{
    /**
     * Newest-first page of transitions for one object. UUIDv7 ids are
     * time-ordered, so `$before` (an entry id from a previous page) is
     * the whole cursor. Returns up to `$limit` entries.
     *
     * @return list<TransitionLogEntry>
     */
    public function pageForObject(Uuid $objectId, int $limit, ?Uuid $before = null): array;

    /**
     * Latest occurrence of the given transition for an object — e.g.
     * "who sent this to review" for queue metadata and task routing.
     */
    public function latestForObject(Uuid $objectId, string $transition): ?TransitionLogEntry;
}
