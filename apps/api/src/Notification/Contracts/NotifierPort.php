<?php

declare(strict_types=1);

namespace App\Notification\Contracts;

use Symfony\Component\Uid\Uuid;

/**
 * WFL-P2-02 (#2421) — cross-BC write seam for persistent in-app
 * notifications. Producers (workflow fan-out first, tasks in WFL-P4-02,
 * future modules) target explicit user ids; recipient RESOLUTION
 * (who holds a permission) stays with the producer via the Identity
 * seam, so this port never grows an RBAC dependency.
 *
 * Implementations persist rows (persist-only, riding the caller's
 * flush when inside a write path, flushing themselves when consumed
 * from Messenger) and push a live Mercure update per recipient.
 */
interface NotifierPort
{
    /**
     * @param list<Uuid>                $userIds
     * @param array<string, mixed>|null $payload
     */
    public function notifyUsers(array $userIds, string $type, ?array $payload = null): void;
}
