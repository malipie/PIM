<?php

declare(strict_types=1);

namespace App\Integration\Generic\Infrastructure\ApiPlatform\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Integration\Generic\Domain\Entity\Connection;
use App\Integration\Generic\Domain\Entity\FieldMapping;
use App\Integration\Generic\Domain\Entity\RemoteEndpoint;
use App\Integration\Generic\Domain\Enum\MappingDirection;
use App\Integration\Generic\Domain\Repository\ConnectionRepositoryInterface;
use App\Integration\Generic\Domain\Repository\FieldMappingRepositoryInterface;
use App\Integration\Generic\Domain\Repository\RemoteEndpointRepositoryInterface;
use App\Integration\Generic\Infrastructure\ApiPlatform\Resource\FieldMappingInput;
use App\Integration\Generic\Infrastructure\ApiPlatform\Resource\FieldMappingPatchInput;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Write path for the `FieldMapping` resource (APIC-P2-08). Resolves the parent
 * connection tenant-scoped (a cross-tenant or missing id is a 404) and
 * persists. Any applied change on a PATCH bumps the mapping version so reuse
 * can pin a revision. The tenant is stamped on persist by the listener.
 *
 * @implements ProcessorInterface<FieldMappingInput|FieldMappingPatchInput|FieldMapping, FieldMapping|null>
 */
final readonly class FieldMappingProcessor implements ProcessorInterface
{
    public function __construct(
        private ConnectionRepositoryInterface $connections,
        private FieldMappingRepositoryInterface $mappings,
        private RemoteEndpointRepositoryInterface $endpoints,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?FieldMapping
    {
        if ($operation instanceof DeleteOperationInterface) {
            $this->mappings->remove($this->require($uriVariables));

            return null;
        }

        if ($operation instanceof Post) {
            return $this->handlePost($data);
        }

        if ($operation instanceof Patch) {
            return $this->handlePatch($data, $uriVariables);
        }

        throw new LogicException(\sprintf('FieldMappingProcessor cannot handle operation "%s".', $operation::class));
    }

    private function handlePost(mixed $data): FieldMapping
    {
        if (!$data instanceof FieldMappingInput) {
            throw new LogicException('FieldMappingProcessor expects FieldMappingInput on Post.');
        }

        $connection = $this->requireConnection($data->connection);

        $mapping = new FieldMapping(
            $connection,
            $data->pimTarget,
            $data->remoteFieldPath,
            MappingDirection::from($data->direction),
        );
        $mapping->setMatchKey($data->isMatchKey);
        if (null !== $data->endpoint && '' !== $data->endpoint) {
            $mapping->setEndpoint($this->requireEndpointOf($connection, $data->endpoint));
        }

        $this->mappings->save($mapping);

        return $mapping;
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function handlePatch(mixed $data, array $uriVariables): FieldMapping
    {
        if (!$data instanceof FieldMappingPatchInput) {
            throw new LogicException('FieldMappingProcessor expects FieldMappingPatchInput on Patch.');
        }

        $mapping = $this->require($uriVariables);
        $changed = false;

        if (null !== $data->pimTarget) {
            $mapping->setPimTarget($data->pimTarget);
            $changed = true;
        }
        if (null !== $data->remoteFieldPath) {
            $mapping->setRemoteFieldPath($data->remoteFieldPath);
            $changed = true;
        }
        if (null !== $data->direction) {
            $mapping->setDirection(MappingDirection::from($data->direction));
            $changed = true;
        }
        if (null !== $data->isMatchKey) {
            $mapping->setMatchKey($data->isMatchKey);
            $changed = true;
        }
        if (null !== $data->endpoint) {
            $mapping->setEndpoint(
                '' === $data->endpoint ? null : $this->requireEndpointOf($mapping->getConnection(), $data->endpoint),
            );
            $changed = true;
        }

        if ($changed) {
            $mapping->bumpVersion();
        }

        $this->mappings->save($mapping);

        return $mapping;
    }

    /** Resolves the endpoint tenant-scoped and requires it on the same connection (422 otherwise). */
    private function requireEndpointOf(Connection $connection, string $id): RemoteEndpoint
    {
        $endpoint = $this->endpoints->findById($this->parseId($id, 'endpoint'));
        if (!$endpoint instanceof RemoteEndpoint) {
            throw new NotFoundHttpException('RemoteEndpoint was not found.');
        }
        if (!$endpoint->getConnectionId()->equals($connection->getId())) {
            throw new UnprocessableEntityHttpException('Endpoint belongs to a different connection.');
        }

        return $endpoint;
    }

    private function requireConnection(string $id): Connection
    {
        $connection = $this->connections->findById($this->parseId($id, 'connection'));
        if (!$connection instanceof Connection) {
            throw new NotFoundHttpException('Connection was not found.');
        }

        return $connection;
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function require(array $uriVariables): FieldMapping
    {
        $raw = $uriVariables['id'] ?? null;
        $id = $raw instanceof Uuid ? $raw : $this->parseId(\is_string($raw) ? $raw : '', 'id');

        $mapping = $this->mappings->findById($id);
        if (!$mapping instanceof FieldMapping) {
            throw new NotFoundHttpException('FieldMapping was not found.');
        }

        return $mapping;
    }

    private function parseId(string $raw, string $field): Uuid
    {
        if ('' === $raw) {
            throw new NotFoundHttpException(\sprintf('Missing %s identifier.', $field));
        }

        try {
            return Uuid::fromString($raw);
        } catch (InvalidArgumentException) {
            throw new NotFoundHttpException(\sprintf('Invalid %s identifier.', $field));
        }
    }
}
