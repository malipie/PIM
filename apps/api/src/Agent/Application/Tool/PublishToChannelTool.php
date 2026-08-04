<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Identity\Contracts\Policy\UserScopedPermissionCheckerInterface;
use App\Integration\Contracts\ChannelPublishPort;

/**
 * AGENT-P7-02 (#1982) — "publish the selection to Shopify" as an
 * ACTION over the Integration seam. ENGINE-GATED: until epics 0.8/0.9
 * deliver the connectors, the port's default answers available=false
 * and the model relays the honest boundary (§5.6/§6) plus the working
 * alternatives (export/feed). The per-user CHANNEL SCOPE (§6) is
 * enforced here - a channel outside the user's scope refuses before
 * the port is even asked.
 */
final readonly class PublishToChannelTool implements AgentToolInterface
{
    public function __construct(
        private ChannelPublishPort $publisher,
        private UserScopedPermissionCheckerInterface $userScopes,
    ) {
    }

    public function name(): string
    {
        return 'publish_to_channel';
    }

    public function description(): string
    {
        return 'Publish selected objects to a sales channel through the integration engine. '
            .'If the result says available=false, relay the reason honestly and suggest trigger_export or generate_feed instead. '
            .'Requires explicit object ids - confirm the selection with the user first.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'channel_code' => ['type' => 'string', 'description' => 'Sales channel code (e.g. shopify).'],
                'object_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'UUIDs of the objects to publish.'],
            ],
            'required' => ['channel_code', 'object_ids'],
        ];
    }

    public function requiredPermission(): string
    {
        return 'publications.publish_unpublish';
    }

    /**
     * ADR-0032 (#2742) — this stays `Action` only while
     * {@see \App\Channel\Contracts\ChannelPublishPort} has no real
     * implementation (`available=false`, so nothing is published). The decision
     * is recorded: publishing through the agent MUST move to `ToolKind::Write`
     * and materialise into `pending_changes` BEFORE the first connector lands
     * (epic 0.8 BaseLinker / 0.9 Shopify). Publication has external effects a
     * PIM-side unpublish cannot undo — the offer was already visible, indexed
     * and possibly ingested by price comparison sites.
     */
    public function kind(): ToolKind
    {
        return ToolKind::Action;
    }

    public function execute(array $arguments, AgentToolContext $context): array
    {
        $channelCode = \is_string($arguments['channel_code'] ?? null) ? trim($arguments['channel_code']) : '';
        if ('' === $channelCode) {
            return ['error' => 'channel_code is required.'];
        }

        $objectIds = [];
        $rawIds = \is_array($arguments['object_ids'] ?? null) ? $arguments['object_ids'] : [];
        foreach ($rawIds as $id) {
            if (\is_string($id) && '' !== $id) {
                $objectIds[] = $id;
            }
        }
        if ([] === $objectIds) {
            return ['error' => 'object_ids must be a non-empty list - confirm the selection with the user.'];
        }

        // §6 — the run acts within the USER's channel scope; outside it
        // the tool refuses before the engine is asked.
        if (!$this->userScopes->canEditChannel($context->userId, $channelCode)) {
            return [
                'available' => false,
                'reason' => \sprintf('Channel "%s" is outside your channel scope - ask an operator with access to that channel.', $channelCode),
            ];
        }

        return $this->publisher->publishSelection($channelCode, $objectIds);
    }
}
