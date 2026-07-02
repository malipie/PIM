<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\PendingChanges;

/**
 * Lifecycle of a single proposed change row (ADR-0024 c).
 *
 * The whole batch moves together in MVP (all-or-nothing approval);
 * the per-row status still exists so a partial-accept hook can land
 * later without a schema migration.
 */
enum PendingChangeStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
