<?php

declare(strict_types=1);

namespace App\Channel\Presentation\Controller;

use App\Channel\Application\Command\AddChannelCategoryNode\AddChannelCategoryNodeCommand;
use App\Channel\Application\Command\CreateNavigationTreeRoot\CreateNavigationTreeRootCommand;
use App\Channel\Application\Command\DeleteChannelCategoryNode\DeleteChannelCategoryNodeCommand;
use App\Channel\Application\Command\MoveChannelCategoryNode\MoveChannelCategoryNodeCommand;
use App\Channel\Application\Command\UpdateChannelCategoryNode\UpdateChannelCategoryNodeCommand;
use App\Channel\Domain\Entity\Channel;
use App\Channel\Domain\Entity\ChannelCategoryNode;
use App\Channel\Domain\Repository\ChannelCategoryNodeRepositoryInterface;
use App\Channel\Domain\Repository\ChannelRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use JsonException;
use LogicException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;

/**
 * CHC-01 (#1284) — per-channel navigation-tree CRUD.
 *
 * Nested under a channel rather than 1:1 CRUD on an entity (root creation
 * also stamps
 * `channel.categoryTreeRootId`, delete cascades a subtree), so it lives as a
 * thin controller over the CQRS command bus instead of an API Platform
 * sub-resource. The master category tree and EffectiveAttributeGroupResolver
 * never see this surface.
 */
final class ChannelNavigationTreeController
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly ChannelRepositoryInterface $channels,
        private readonly ChannelCategoryNodeRepositoryInterface $nodes,
    ) {
    }

    #[Route('/api/channels/{channelId}/navigation-tree', name: 'pim_channel_navtree_list', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'channel', action: 'read', anyOf: [
        'channel.read',
        'publications.view',
    ])]
    public function list(string $channelId): JsonResponse
    {
        $channel = $this->requireChannel($channelId);

        return new JsonResponse(array_map(
            fn (ChannelCategoryNode $node): array => $this->serialize($node),
            $this->nodes->findAllForChannel($channel),
        ));
    }

    #[Route('/api/channels/{channelId}/navigation-tree', name: 'pim_channel_navtree_create_root', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'channel', action: 'write', anyOf: [
        'channel.write',
        'publications.publish_unpublish',
    ])]
    public function createRoot(string $channelId, Request $request): JsonResponse
    {
        $data = $this->decode($request);

        // `code` is optional — the handler defaults an absent code to the root's
        // uuid-hex (unique per channel; channels may have multiple roots).
        $id = $this->dispatchForId(new CreateNavigationTreeRootCommand(
            channelId: $this->uuid($channelId),
            code: $this->optionalString($data, 'code'),
            label: $this->labelMap($data['label'] ?? []),
            externalCode: $this->optionalString($data, 'externalCode'),
        ));

        return new JsonResponse($this->serialize($this->requireNode($id)), Response::HTTP_CREATED);
    }

    #[Route('/api/channels/{channelId}/navigation-tree/nodes', name: 'pim_channel_navtree_add_node', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'channel', action: 'write', anyOf: [
        'channel.write',
        'publications.publish_unpublish',
    ])]
    public function addNode(string $channelId, Request $request): JsonResponse
    {
        $data = $this->decode($request);
        $parentId = $this->optionalString($data, 'parentId');
        if (null === $parentId) {
            throw new UnprocessableEntityHttpException('"parentId" is required.');
        }

        // CHC-09: `code` is optional — the tree editor sends only name + externalId;
        // the handler defaults an absent code to the node's uuid-hex.
        $id = $this->dispatchForId(new AddChannelCategoryNodeCommand(
            channelId: $this->uuid($channelId),
            parentId: $this->uuid($parentId),
            code: $this->optionalString($data, 'code'),
            label: $this->labelMap($data['label'] ?? []),
            externalCode: $this->optionalString($data, 'externalCode'),
        ));

        return new JsonResponse($this->serialize($this->requireNode($id)), Response::HTTP_CREATED);
    }

    /**
     * CHC-09 (#1302) — re-parent a node (and its subtree) under a different node.
     */
    #[Route('/api/channels/{channelId}/navigation-tree/nodes/{nodeId}/move', name: 'pim_channel_navtree_move_node', methods: ['PATCH'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'channel', action: 'write', anyOf: [
        'channel.write',
        'publications.publish_unpublish',
    ])]
    public function moveNode(string $channelId, string $nodeId, Request $request): JsonResponse
    {
        $data = $this->decode($request);
        $newParentId = $this->optionalString($data, 'newParentId');
        if (null === $newParentId) {
            throw new UnprocessableEntityHttpException('"newParentId" is required.');
        }
        $nodeUuid = $this->uuid($nodeId);

        $this->dispatch(new MoveChannelCategoryNodeCommand(
            channelId: $this->uuid($channelId),
            nodeId: $nodeUuid,
            newParentId: $this->uuid($newParentId),
        ));

        return new JsonResponse($this->serialize($this->requireNode($nodeUuid)));
    }

    #[Route('/api/channels/{channelId}/navigation-tree/nodes/{nodeId}', name: 'pim_channel_navtree_patch_node', methods: ['PATCH'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'channel', action: 'write', anyOf: [
        'channel.write',
        'publications.publish_unpublish',
    ])]
    public function patchNode(string $channelId, string $nodeId, Request $request): JsonResponse
    {
        $data = $this->decode($request);
        $nodeUuid = $this->uuid($nodeId);

        $this->dispatch(new UpdateChannelCategoryNodeCommand(
            channelId: $this->uuid($channelId),
            nodeId: $nodeUuid,
            label: \array_key_exists('label', $data) ? $this->labelMap($data['label']) : null,
            changeExternalCode: \array_key_exists('externalCode', $data),
            externalCode: $this->optionalString($data, 'externalCode'),
        ));

        return new JsonResponse($this->serialize($this->requireNode($nodeUuid)));
    }

    #[Route('/api/channels/{channelId}/navigation-tree/nodes/{nodeId}', name: 'pim_channel_navtree_delete_node', methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'channel', action: 'write', anyOf: [
        'channel.write',
        'publications.publish_unpublish',
    ])]
    public function deleteNode(string $channelId, string $nodeId): Response
    {
        $this->dispatch(new DeleteChannelCategoryNodeCommand(
            channelId: $this->uuid($channelId),
            nodeId: $this->uuid($nodeId),
        ));

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

    private function requireNode(Uuid $id): ChannelCategoryNode
    {
        $node = $this->nodes->findById($id);
        if (null === $node) {
            throw new NotFoundHttpException(\sprintf('Navigation node "%s" was not found.', $id->toRfc4122()));
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ChannelCategoryNode $node): array
    {
        return [
            'id' => $node->getId()->toRfc4122(),
            'channelId' => $node->getChannelId()->toRfc4122(),
            'parentId' => $node->getParentId()?->toRfc4122(),
            'code' => $node->getCode(),
            'label' => $node->getLabel(),
            'path' => $node->getPath(),
            'externalCode' => $node->getExternalCode(),
            'createdAt' => $node->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Request $request): array
    {
        $content = $request->getContent();
        if ('' === $content) {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BadRequestHttpException('Request body is not valid JSON.');
        }

        if (!\is_array($decoded)) {
            throw new BadRequestHttpException('Request body must be a JSON object.');
        }

        $object = [];
        foreach ($decoded as $key => $value) {
            if (!\is_string($key)) {
                throw new BadRequestHttpException('Request body must be a JSON object.');
            }
            $object[$key] = $value;
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function optionalString(array $data, string $field): ?string
    {
        if (!\array_key_exists($field, $data) || null === $data[$field]) {
            return null;
        }
        if (!\is_string($data[$field])) {
            throw new UnprocessableEntityHttpException(\sprintf('"%s" must be a string.', $field));
        }

        return $data[$field];
    }

    /**
     * @return array<string, string>
     */
    private function labelMap(mixed $value): array
    {
        if (!\is_array($value)) {
            throw new UnprocessableEntityHttpException('"label" must be a locale → string map.');
        }

        $label = [];
        foreach ($value as $locale => $text) {
            if (!\is_string($locale) || !\is_string($text)) {
                throw new UnprocessableEntityHttpException('"label" must be a locale → string map.');
            }
            $label[$locale] = $text;
        }

        return $label;
    }

    private function uuid(string $value): Uuid
    {
        if (!Uuid::isValid($value)) {
            throw new UnprocessableEntityHttpException(\sprintf('"%s" is not a valid UUID.', $value));
        }

        return Uuid::fromString($value);
    }

    private function dispatch(object $message): Envelope
    {
        try {
            return $this->bus->dispatch($message);
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof HttpException) {
                throw $previous;
            }

            throw $e;
        }
    }

    private function dispatchForId(object $message): Uuid
    {
        $stamp = $this->dispatch($message)->last(HandledStamp::class);
        $result = $stamp instanceof HandledStamp ? $stamp->getResult() : null;
        if (!$result instanceof Uuid) {
            throw new LogicException('Navigation-tree command did not return a node id.');
        }

        return $result;
    }
}
