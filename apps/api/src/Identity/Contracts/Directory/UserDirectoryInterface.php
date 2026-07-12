<?php

declare(strict_types=1);

namespace App\Identity\Contracts\Directory;

/**
 * WFL redesign (#2517) — cross-BC seam to turn actor / submitter UUIDs
 * into a human label + email for the review queue and task cards. Read
 * side only; other bounded contexts store user ids and resolve display
 * data through this port instead of reaching into Identity entities
 * (deptrac).
 */
interface UserDirectoryInterface
{
    /**
     * Batch-resolve user ids to their display name + email. Unknown ids
     * are omitted from the result. Tenant isolation is enforced by RLS /
     * the query scope.
     *
     * @param list<string> $userIds RFC-4122 uuids
     *
     * @return array<string, array{name: string, email: string}> keyed by user id
     */
    public function resolve(array $userIds): array;
}
