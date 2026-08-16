<?php

declare(strict_types=1);

namespace App\Channel\Presentation\Controller;

use App\Channel\Application\Message\ReconcileChannelPlacementsForCategory;
use App\Channel\Domain\Entity\Channel;
use App\Channel\Domain\Entity\ChannelCategoryNodeMapping;
use App\Channel\Domain\Repository\ChannelCategoryNodeMappingRepositoryInterface;
use App\Channel\Domain\Repository\ChannelCategoryNodeRepositoryInterface;
use App\Channel\Domain\Repository\ChannelRepositoryInterface;
use App\Channel\Domain\Repository\ObjectChannelPlacementRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;

/**
 * CHC-06 (#1289) — node-mapping CRUD: "master category → channel navigation
 * node(s)". Drives CHC-07 auto-assignment so products inherit a channel
 * placement from their master category without per-product work.
 *
 * Lives in the Channel context; the master category is referenced by Uuid
 * (no cross-BC Catalog import). Master kind is validated with a tenant-scoped
 * raw query on `objects` (table access, not an entity dependency).
 */
final class ChannelNodeMappingController
{
    public function __construct(
        private readonly ChannelRepositoryInterface $channels,
        private readonly ChannelCategoryNodeMappingRepositoryInterface $mappings,
        private readonly ChannelCategoryNodeRepositoryInterface $nodes,
        private readonly ObjectChannelPlacementRepositoryInterface $placements,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * #1314 — after a mapping change, back-fill placements for every product in
     * the affected master category (off-thread).
     */
    private function dispatchBackfill(Channel $channel, Uuid $masterCategoryId): void
    {
        $tenant = $channel->getTenant();
        if (null === $tenant) {
            return;
        }
        $this->bus->dispatch(new ReconcileChannelPlacementsForCategory(
            $masterCategoryId->toRfc4122(),
            $tenant->getId()->toRfc4122(),
        ));
    }

    #[Route('/api/channels/{channelId}/node-mappings', name: 'pim_channel_node_mappings_list', methods: ['GET'], format: 'json')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'channel', action: 'read', anyOf: [
        'channel.read',
        'publications.view',
    ])]
    public function list(string $channelId): JsonResponse
    {
        $channel = $this->requireChannel($channelId);

        $rows = array_map(
            static fn (ChannelCategoryNodeMapping $m): array => [
                'masterCategoryId' => $m->getMasterCategoryId()->toRfc4122(),
                'channelNodeIds' => $m->getChannelNodeIds(),
            ],
            $this->mappings->findByChannel($channel),
        );

        return new JsonResponse(['member' => $rows, 'totalItems' => \count($rows)]);
    }

    /**
     * CHC-08 (#1291) — per-node "affected products" badge: how many products
     * are placed on each navigation node of the channel.
     */
    #[Route('/api/channels/{channelId}/node-placement-counts', name: 'pim_channel_node_placement_counts', methods: ['GET'], format: 'json')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'channel', action: 'read', anyOf: [
        'channel.read',
        'publications.view',
    ])]
    public function placementCounts(string $channelId): JsonResponse
    {
        $channel = $this->requireChannel($channelId);

        $rows = [];
        foreach ($this->placements->countByNodeForChannel($channel) as $nodeId => $count) {
            $rows[] = ['nodeId' => $nodeId, 'productCount' => $count];
        }

        return new JsonResponse(['member' => $rows, 'totalItems' => \count($rows)]);
    }

    /**
     * CHC-08 (#1291) — bulk "clear all channel mappings". Removes every
     * node mapping of the channel via tenant-filtered SELECT + per-row remove
     * (a DQL bulk DELETE would bypass the tenant filter — not tenant-safe).
     */
    #[Route('/api/channels/{channelId}/node-mappings', name: 'pim_channel_node_mappings_clear', methods: ['DELETE'], format: 'json')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'channel', action: 'write', anyOf: [
        'channel.write',
        'publications.publish_unpublish',
    ])]
    public function clearAll(string $channelId): JsonResponse
    {
        $channel = $this->requireChannel($channelId);

        $deleted = 0;
        $affectedMasters = [];
        foreach ($this->mappings->findByChannel($channel) as $mapping) {
            $affectedMasters[] = $mapping->getMasterCategoryId();
            $this->mappings->remove($mapping);
            ++$deleted;
        }
        foreach ($affectedMasters as $masterId) {
            $this->dispatchBackfill($channel, $masterId);
        }

        return new JsonResponse(['deleted' => $deleted]);
    }

    #[Route('/api/channels/{channelId}/node-mappings/{masterCategoryId}', name: 'pim_channel_node_mappings_put', methods: ['PUT'], format: 'json')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'channel', action: 'write', anyOf: [
        'channel.write',
        'publications.publish_unpublish',
    ])]
    public function put(string $channelId, string $masterCategoryId, Request $request): JsonResponse
    {
        $channel = $this->requireChannel($channelId);
        $masterId = $this->uuid($masterCategoryId);
        $this->assertMasterIsCategory($channel, $masterId);

        $nodeIds = $this->decodeNodeIds($request);
        foreach ($nodeIds as $nodeId) {
            $node = $this->nodes->findById($this->uuid($nodeId));
            if (null === $node || !$node->getChannel()->getId()->equals($channel->getId())) {
                throw new UnprocessableEntityHttpException(\sprintf(
                    'Node "%s" does not belong to channel "%s".',
                    $nodeId,
                    $channel->getCode(),
                ));
            }
        }

        $mapping = $this->mappings->upsert($channel, $masterId, $nodeIds);
        $this->dispatchBackfill($channel, $masterId);

        return new JsonResponse([
            'masterCategoryId' => $mapping->getMasterCategoryId()->toRfc4122(),
            'channelNodeIds' => $mapping->getChannelNodeIds(),
        ]);
    }

    #[Route('/api/channels/{channelId}/node-mappings/{masterCategoryId}', name: 'pim_channel_node_mappings_delete', methods: ['DELETE'], format: 'json')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'channel', action: 'write', anyOf: [
        'channel.write',
        'publications.publish_unpublish',
    ])]
    public function delete(string $channelId, string $masterCategoryId): Response
    {
        $channel = $this->requireChannel($channelId);
        $mapping = $this->mappings->findByChannelAndMaster($channel, $this->uuid($masterCategoryId));
        if (null === $mapping) {
            throw new NotFoundHttpException('No node mapping for this master category on the channel.');
        }

        $masterId = $mapping->getMasterCategoryId();
        $this->mappings->remove($mapping);
        $this->dispatchBackfill($channel, $masterId);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function requireChannel(string $channelId): Channel
    {
        $channel = $this->channels->findById($this->uuid($channelId));
        if (null === $channel) {
            throw new NotFoundHttpException(\sprintf('Channel "%s" was not found.', $channelId));
        }

        return $channel;
    }

    private function assertMasterIsCategory(Channel $channel, Uuid $masterId): void
    {
        $tenant = $channel->getTenant();
        if (null === $tenant) {
            throw new UnprocessableEntityHttpException('Channel has no tenant.');
        }

        // tenant-safe: explicit tenant_id filter; reads master object kind only.
        $kind = $this->em->getConnection()->fetchOne(
            'SELECT kind FROM objects WHERE id = CAST(:id AS uuid) AND tenant_id = CAST(:tenant AS uuid)',
            ['id' => $masterId->toRfc4122(), 'tenant' => $tenant->getId()->toRfc4122()],
        );
        if ('category' !== $kind) {
            throw new UnprocessableEntityHttpException(\sprintf(
                'Object "%s" is not a master category.',
                $masterId->toRfc4122(),
            ));
        }
    }

    /**
     * @return list<string>
     */
    private function decodeNodeIds(Request $request): array
    {
        $content = $request->getContent();
        try {
            $data = '' === $content ? [] : json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BadRequestHttpException('Request body is not valid JSON.');
        }
        if (!\is_array($data) || !isset($data['nodeIds']) || !\is_array($data['nodeIds'])) {
            throw new UnprocessableEntityHttpException('"nodeIds" (array of UUID strings) is required.');
        }

        $ids = [];
        foreach ($data['nodeIds'] as $id) {
            if (!\is_string($id) || '' === $id) {
                throw new UnprocessableEntityHttpException('"nodeIds" must contain only non-empty UUID strings.');
            }
            $ids[] = $id;
        }

        return $ids;
    }

    private function uuid(string $value): Uuid
    {
        if (!Uuid::isValid($value)) {
            throw new UnprocessableEntityHttpException(\sprintf('"%s" is not a valid UUID.', $value));
        }

        return Uuid::fromString($value);
    }
}
