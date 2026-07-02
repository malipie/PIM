<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\PendingChanges;

/**
 * What kind of catalog mutation a pending change proposes (ADR-0024 c).
 *
 *   - `Value`    — an ObjectValue write (before/after are value envelopes
 *                  per docs/api/jsonb-schemas.md).
 *   - `Schema`   — a modeling operation (new/changed attribute or group).
 *   - `Category` — a category assignment (add/remove/move).
 */
enum PendingChangeType: string
{
    case Value = 'value';
    case Schema = 'schema';
    case Category = 'category';
}
