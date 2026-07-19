<?php

declare(strict_types=1);

namespace App\Integration\Generic\Application\Sync;

use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Tracks which connection's sync run is executing in this process (#2636).
 *
 * Catalog writes performed BY a sync run (inbound upserts, outbound remote-id
 * capture) emit the same `ObjectAttributesChanged` event as a manual edit;
 * without a guard the {@see \App\Integration\Generic\Application\Subscriber\OutboundTriggerSubscriber}
 * would re-enqueue an outbound run of the very connection that is writing —
 * an N-objects run would fan out N further full runs. The subscriber skips
 * bindings of the active connection; bindings of OTHER connections still
 * trigger, so cross-connection propagation (A → PIM → B) keeps working.
 *
 * ResetInterface: FrankenPHP worker hygiene — a crashed run must not leave the
 * scope active for the next message.
 */
final class SyncRunScope implements ResetInterface
{
    private ?Uuid $activeConnectionId = null;

    public function enter(Uuid $connectionId): void
    {
        $this->activeConnectionId = $connectionId;
    }

    public function leave(): void
    {
        $this->activeConnectionId = null;
    }

    public function activeConnectionId(): ?Uuid
    {
        return $this->activeConnectionId;
    }

    public function reset(): void
    {
        $this->activeConnectionId = null;
    }
}
