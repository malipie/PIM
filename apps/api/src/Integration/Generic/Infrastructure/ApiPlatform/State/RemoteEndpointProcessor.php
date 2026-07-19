<?php

declare(strict_types=1);

namespace App\Integration\Generic\Infrastructure\ApiPlatform\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Integration\Generic\Application\Validation\DescriptorValidator;
use App\Integration\Generic\Domain\Entity\Connection;
use App\Integration\Generic\Domain\Entity\RemoteEndpoint;
use App\Integration\Generic\Domain\Enum\RemoteEndpointRole;
use App\Integration\Generic\Domain\Exception\InvalidDescriptorException;
use App\Integration\Generic\Domain\Repository\ConnectionRepositoryInterface;
use App\Integration\Generic\Domain\Repository\RemoteEndpointRepositoryInterface;
use App\Integration\Generic\Infrastructure\ApiPlatform\Resource\RemoteEndpointInput;
use App\Integration\Generic\Infrastructure\ApiPlatform\Resource\RemoteEndpointPatchInput;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Write path for the `RemoteEndpoint` resource (APIC-P2-05). Resolves the
 * parent connection tenant-scoped (a cross-tenant or missing id is a 404),
 * validates the path template via the SSRF descriptor wall (APIC-P1-04 → 422),
 * and persists. The tenant is stamped on persist by the listener.
 *
 * @implements ProcessorInterface<RemoteEndpointInput|RemoteEndpointPatchInput|RemoteEndpoint, RemoteEndpoint|null>
 */
final readonly class RemoteEndpointProcessor implements ProcessorInterface
{
    public function __construct(
        private ConnectionRepositoryInterface $connections,
        private RemoteEndpointRepositoryInterface $endpoints,
        private DescriptorValidator $descriptors,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?RemoteEndpoint
    {
        if ($operation instanceof DeleteOperationInterface) {
            $this->endpoints->remove($this->require($uriVariables));

            return null;
        }

        if ($operation instanceof Post) {
            return $this->handlePost($data);
        }

        if ($operation instanceof Patch) {
            return $this->handlePatch($data, $uriVariables);
        }

        throw new LogicException(\sprintf('RemoteEndpointProcessor cannot handle operation "%s".', $operation::class));
    }

    private function handlePost(mixed $data): RemoteEndpoint
    {
        if (!$data instanceof RemoteEndpointInput) {
            throw new LogicException('RemoteEndpointProcessor expects RemoteEndpointInput on Post.');
        }

        $connection = $this->requireConnection($data->connection);
        $this->validatePathTemplate($data->pathTemplate);

        $endpoint = new RemoteEndpoint(
            $connection,
            RemoteEndpointRole::from($data->role),
            $data->httpMethod,
            $data->pathTemplate,
        );
        $endpoint->setQueryParams($data->queryParams);
        $endpoint->setRequestBodyTemplate($data->requestBodyTemplate);
        $endpoint->setPagination($data->pagination);
        $endpoint->setRecordSelector($data->recordSelector);
        $endpoint->setRequestFormat($data->requestFormat);
        $endpoint->setResponseFormat($data->responseFormat);

        $this->endpoints->save($endpoint);

        return $endpoint;
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function handlePatch(mixed $data, array $uriVariables): RemoteEndpoint
    {
        if (!$data instanceof RemoteEndpointPatchInput) {
            throw new LogicException('RemoteEndpointProcessor expects RemoteEndpointPatchInput on Patch.');
        }

        $endpoint = $this->require($uriVariables);

        if (null !== $data->role) {
            $endpoint->setRole(RemoteEndpointRole::from($data->role));
        }
        if (null !== $data->httpMethod) {
            $endpoint->setHttpMethod($data->httpMethod);
        }
        if (null !== $data->pathTemplate) {
            $this->validatePathTemplate($data->pathTemplate);
            $endpoint->setPathTemplate($data->pathTemplate);
        }
        if (null !== $data->queryParams) {
            $endpoint->setQueryParams($data->queryParams);
        }
        if (null !== $data->requestBodyTemplate) {
            $endpoint->setRequestBodyTemplate($data->requestBodyTemplate);
        }
        if (null !== $data->pagination) {
            $endpoint->setPagination($data->pagination);
        }
        if (null !== $data->recordSelector) {
            $endpoint->setRecordSelector($data->recordSelector);
        }
        if (null !== $data->requestFormat) {
            $endpoint->setRequestFormat($data->requestFormat);
        }
        if (null !== $data->responseFormat) {
            $endpoint->setResponseFormat($data->responseFormat);
        }

        $this->endpoints->save($endpoint);

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
    private function require(array $uriVariables): RemoteEndpoint
    {
        $raw = $uriVariables['id'] ?? null;
        $id = $raw instanceof Uuid ? $raw : $this->parseId(\is_string($raw) ? $raw : '', 'id');

        $endpoint = $this->endpoints->findById($id);
        if (!$endpoint instanceof RemoteEndpoint) {
            throw new NotFoundHttpException('RemoteEndpoint was not found.');
        }

        return $endpoint;
    }

    private function validatePathTemplate(string $pathTemplate): void
    {
        try {
            $this->descriptors->assertValidPathTemplate($pathTemplate);
        } catch (InvalidDescriptorException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
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
