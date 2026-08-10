<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Marker for async messages that legitimately have NO tenant — the
 * counterpart of {@see TenantAwareMessage} (#2803).
 *
 * {@see \App\Shared\Infrastructure\Messenger\TenantContextRebindingMiddleware}
 * treats an unbound async message as a bug and throws, which is the right
 * default: a payload crossing a process boundary without declaring whose
 * data it touches is how cross-tenant leaks start. But a few messages are
 * infrastructure, not domain work, and have no tenant to declare:
 *
 *   - {@see \App\Shared\Infrastructure\Scheduler\RunMaintenanceCommand} —
 *     runs `pim:audit:cleanup`, `pim:tenants:purge-deleted` and friends.
 *     `purge-deleted` inspects EVERY tenant by definition; binding it to
 *     one would be wrong, not merely redundant.
 *
 * Opting in is deliberately an explicit act. The alternative — an
 * allow-list of class names inside the middleware — puts the decision far
 * away from the message it describes, where the next person to add a
 * scheduler message will not think to look. That is exactly how this bug
 * shipped: the guard (W2-11) and the maintenance schedule (AUD-051) were
 * each correct on their own and nobody owned the seam between them.
 *
 * **Do not implement this to silence an exception.** If a message touches
 * tenant-scoped data, the fix is a `TenantStamp` on dispatch or
 * {@see TenantAwareMessage} on the payload. This marker is for work that
 * is genuinely global; RLS and the Doctrine tenant filter will not shield
 * a handler that runs under it.
 */
interface TenantAgnosticMessage
{
}
