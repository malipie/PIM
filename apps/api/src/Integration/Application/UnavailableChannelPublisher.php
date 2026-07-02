<?php

declare(strict_types=1);

namespace App\Integration\Application;

use App\Integration\Contracts\ChannelPublishPort;

/**
 * AGENT-P7-02 (#1982) — the pre-engine default: no integrations exist
 * yet (BaseLinker = epik 0.8, Shopify = epik 0.9, Faza 1), so every
 * publish answers available=false with the reason. Epics 0.8/0.9
 * replace the ChannelPublishPort alias with the real dispatcher and
 * the agent tool lights up unchanged (ADR-0024 b).
 */
final readonly class UnavailableChannelPublisher implements ChannelPublishPort
{
    public function publishableChannels(): array
    {
        return [];
    }

    public function publishSelection(string $channelCode, array $objectIds): array
    {
        return [
            'available' => false,
            'reason' => \sprintf(
                'Publishing to "%s" is not available yet - the sales-channel integrations (BaseLinker/Shopify) arrive in Faza 1. Data can be exported today via trigger_export or generate_feed.',
                $channelCode,
            ),
        ];
    }
}
