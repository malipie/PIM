<?php

declare(strict_types=1);

namespace App\Channel\Infrastructure;

use App\Channel\Contracts\ChannelCategoryExternalCodeResolverInterface;
use App\Channel\Domain\Repository\ChannelCategoryNodeMappingRepositoryInterface;
use App\Channel\Domain\Repository\ChannelCategoryNodeRepositoryInterface;
use App\Channel\Domain\Repository\ChannelRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * XMLF-P3-03 — default {@see ChannelCategoryExternalCodeResolverInterface}.
 *
 * Two indexed reads for the whole request (mappings + nodes of the channel —
 * both bounded by the channel tree size, not the catalog), then in-memory
 * joins: master category → mapped node ids (mapping order) → first node with
 * a non-empty external_code. Tenant isolation rides on the repositories
 * (TenantFilter + RLS); an unknown channel resolves to nothing.
 */
final readonly class ChannelCategoryExternalCodeResolver implements ChannelCategoryExternalCodeResolverInterface
{
    public function __construct(
        private ChannelRepositoryInterface $channels,
        private ChannelCategoryNodeMappingRepositoryInterface $mappings,
        private ChannelCategoryNodeRepositoryInterface $nodes,
    ) {
    }

    public function resolveExternalCodes(Uuid $channelId, array $masterCategoryIds): array
    {
        if ([] === $masterCategoryIds) {
            return [];
        }
        $channel = $this->channels->findById($channelId);
        if (null === $channel) {
            return [];
        }

        $externalCodeByNodeId = [];
        foreach ($this->nodes->findAllForChannel($channel) as $node) {
            $code = $node->getExternalCode();
            if (null !== $code) {
                $externalCodeByNodeId[$node->getId()->toRfc4122()] = $code;
            }
        }
        if ([] === $externalCodeByNodeId) {
            return [];
        }

        $wanted = array_fill_keys($masterCategoryIds, true);
        $resolved = [];
        foreach ($this->mappings->findByChannel($channel) as $mapping) {
            $masterId = $mapping->getMasterCategoryId()->toRfc4122();
            if (!isset($wanted[$masterId]) || isset($resolved[$masterId])) {
                continue;
            }
            foreach ($mapping->getChannelNodeIds() as $nodeId) {
                if (isset($externalCodeByNodeId[$nodeId])) {
                    $resolved[$masterId] = $externalCodeByNodeId[$nodeId];
                    break;
                }
            }
        }

        return $resolved;
    }
}
