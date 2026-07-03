<?php

declare(strict_types=1);

namespace App\Integration\Contracts;

/**
 * AGENT-P7-02 (#1982) — publish a selection of objects to a sales
 * channel through the INTEGRATION ENGINE (boundary §5.6: the agent
 * never talks to Shopify/BaseLinker itself; throttling, mapping and
 * retries are the integration's job).
 *
 * ENGINE-GATED: the real implementations land with epics 0.8
 * (BaseLinker) and 0.9 (Shopify) in Faza 1. Until then the default
 * adapter answers available=false with an operator-facing reason, so
 * the agent INFORMS instead of pretending (§6).
 */
interface ChannelPublishPort
{
    /**
     * @return list<array{code: string, name: string}> channels a publish engine exists for
     */
    public function publishableChannels(): array;

    /**
     * @param list<string> $objectIds RFC 4122 object UUIDs
     *
     * @return array{available: bool, reason?: string, queued?: int}
     */
    public function publishSelection(string $channelCode, array $objectIds): array;
}
