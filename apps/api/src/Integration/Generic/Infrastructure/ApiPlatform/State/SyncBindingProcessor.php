<?php

declare(strict_types=1);

namespace App\Integration\Generic\Infrastructure\ApiPlatform\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Channel\Contracts\ChannelResolverInterface;
use App\Channel\Contracts\LocaleCodeResolverInterface;
use App\Integration\Generic\Application\Schedule\SyncScheduleDispatcher;
use App\Integration\Generic\Domain\Entity\Connection;
use App\Integration\Generic\Domain\Entity\RemoteEndpoint;
use App\Integration\Generic\Domain\Entity\SyncBinding;
use App\Integration\Generic\Domain\Enum\ConflictPolicy;
use App\Integration\Generic\Domain\Enum\SyncDirection;
use App\Integration\Generic\Domain\Repository\ConnectionRepositoryInterface;
use App\Integration\Generic\Domain\Repository\RemoteEndpointRepositoryInterface;
use App\Integration\Generic\Domain\Repository\SyncBindingRepositoryInterface;
use App\Integration\Generic\Infrastructure\ApiPlatform\Resource\SyncBindingInput;
use App\Integration\Generic\Infrastructure\ApiPlatform\Resource\SyncBindingPatchInput;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Write path for the `SyncBinding` resource (APIC-P3-10). Resolves the parent
 * connection and the optional read/write endpoints tenant-scoped (a cross-tenant
 * or missing id is a 404) and persists. After every create/update the schedule
 * dispatcher refreshes `nextRun`, so a newly-scheduled or re-enabled binding is
 * picked up by the per-minute tick. The tenant is stamped on persist.
 *
 * @implements ProcessorInterface<SyncBindingInput|SyncBindingPatchInput|SyncBinding, SyncBinding|null>
 */
final readonly class SyncBindingProcessor implements ProcessorInterface
{
    public function __construct(
        private SyncBindingRepositoryInterface $bindings,
        private ConnectionRepositoryInterface $connections,
        private RemoteEndpointRepositoryInterface $endpoints,
        private SyncScheduleDispatcher $dispatcher,
        private ChannelResolverInterface $channels,
        private LocaleCodeResolverInterface $localeCodes,
        private TenantContext $tenantContext,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?SyncBinding
    {
        if ($operation instanceof DeleteOperationInterface) {
            $this->bindings->remove($this->require($uriVariables));

            return null;
        }

        if ($operation instanceof Post) {
            return $this->handlePost($data);
        }

        if ($operation instanceof Patch) {
            return $this->handlePatch($data, $uriVariables);
        }

        throw new LogicException(\sprintf('SyncBindingProcessor cannot handle operation "%s".', $operation::class));
    }

    private function handlePost(mixed $data): SyncBinding
    {
        if (!$data instanceof SyncBindingInput) {
            throw new LogicException('SyncBindingProcessor expects SyncBindingInput on Post.');
        }

        $connection = $this->requireConnection($data->connection);

        $binding = new SyncBinding($connection, $this->parseId($data->objectTypeId, 'objectTypeId'), SyncDirection::from($data->direction));
        $binding->setReadEndpoint($this->resolveEndpoint($data->readEndpoint, $connection));
        $binding->setWriteEndpoint($this->resolveEndpoint($data->writeEndpoint, $connection));
        $binding->setSchedule($data->schedule);
        $binding->setConflictPolicy(ConflictPolicy::from($data->conflictPolicy));
        $binding->setMatchKeyMapping($data->matchKeyMapping);
        $binding->setEnabled($data->enabled);
        $binding->setOutboundFilter([] === $data->outboundFilter ? null : $data->outboundFilter);
        $binding->setSourceChannel($this->resolveSourceChannel($data->sourceChannel));
        $binding->setSourceLocale($this->resolveSourceLocale($data->sourceLocale));

        // Persist first so the tenant is stamped, then compute the (jittered)
        // next run off the assigned tenant.
        $this->bindings->save($binding);
        $this->dispatcher->computeNextRun($binding);

        return $binding;
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function handlePatch(mixed $data, array $uriVariables): SyncBinding
    {
        if (!$data instanceof SyncBindingPatchInput) {
            throw new LogicException('SyncBindingProcessor expects SyncBindingPatchInput on Patch.');
        }

        $binding = $this->require($uriVariables);
        $rescheduleNeeded = false;

        if (null !== $data->objectTypeId) {
            $binding->setObjectTypeId($this->parseId($data->objectTypeId, 'objectTypeId'));
        }
        if (null !== $data->direction) {
            $binding->setDirection(SyncDirection::from($data->direction));
        }
        if (null !== $data->readEndpoint) {
            $binding->setReadEndpoint($this->resolveEndpoint($data->readEndpoint, $binding->getConnection()));
        }
        if (null !== $data->writeEndpoint) {
            $binding->setWriteEndpoint($this->resolveEndpoint($data->writeEndpoint, $binding->getConnection()));
        }
        if (null !== $data->schedule) {
            $binding->setSchedule($data->schedule);
            $rescheduleNeeded = true;
        }
        if (null !== $data->conflictPolicy) {
            $binding->setConflictPolicy(ConflictPolicy::from($data->conflictPolicy));
        }
        if (null !== $data->matchKeyMapping) {
            $binding->setMatchKeyMapping($data->matchKeyMapping);
        }
        if (null !== $data->outboundFilter) {
            // `[]` is the explicit "clear the scope" signal (send all again).
            $binding->setOutboundFilter([] === $data->outboundFilter ? null : $data->outboundFilter);
        }
        if (null !== $data->sourceChannel) {
            // '' is the explicit "back to global values" signal (#2667).
            $binding->setSourceChannel($this->resolveSourceChannel($data->sourceChannel));
        }
        if (null !== $data->sourceLocale) {
            $binding->setSourceLocale($this->resolveSourceLocale($data->sourceLocale));
        }
        if (null !== $data->enabled) {
            $binding->setEnabled($data->enabled);
            $rescheduleNeeded = true;
        }

        $this->bindings->save($binding);

        // Disabled bindings never fire; clear the slot. Otherwise refresh it when
        // the schedule or enabled flag changed.
        if (!$binding->isEnabled()) {
            $binding->setNextRun(null);
            $this->bindings->save($binding);
        } elseif ($rescheduleNeeded) {
            $this->dispatcher->computeNextRun($binding);
        }

        return $binding;
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
     * Resolve an endpoint reference, asserting it belongs to the binding's
     * connection (an endpoint from another connection is a 422).
     */
    private function resolveEndpoint(?string $id, Connection $connection): ?RemoteEndpoint
    {
        if (null === $id) {
            return null;
        }

        $endpoint = $this->endpoints->findById($this->parseId($id, 'endpoint'));
        if (!$endpoint instanceof RemoteEndpoint) {
            throw new NotFoundHttpException('RemoteEndpoint was not found.');
        }
        if ($endpoint->getConnectionId()->toRfc4122() !== $connection->getId()->toRfc4122()) {
            throw new UnprocessableEntityHttpException('RemoteEndpoint does not belong to the binding connection.');
        }

        return $endpoint;
    }

    /**
     * Resolve the outbound value-source channel CODE (#2667). null/'' → null
     * (global values); otherwise the code must resolve within the tenant — a
     * typo'd or cross-tenant code is a 422, mirroring {@see resolveEndpoint()}.
     * The CODE is stored (not the id), consistent with the Export session
     * channel contract; the outbound reader re-resolves it per run.
     */
    private function resolveSourceChannel(?string $code): ?string
    {
        if (null === $code || '' === $code) {
            return null;
        }

        if (null === $this->channels->resolveId($code, $this->requireTenant())) {
            throw new UnprocessableEntityHttpException(\sprintf('Unknown source channel "%s".', $code));
        }

        return $code;
    }

    /**
     * Resolve the outbound value-source locale (#2667). Input may be SHORT or
     * BCP-47; the SHORT form is stored (that is what `ObjectValue::$locale`
     * holds). Accepted when the tenant enables it (legacy JSONB list) or when
     * an active tenant locale matches (tenant_locales) — the two sources may
     * drift, so either suffices.
     */
    private function resolveSourceLocale(?string $code): ?string
    {
        if (null === $code || '' === $code) {
            return null;
        }

        $tenant = $this->requireTenant();
        $short = $this->localeCodes->toShort($code);
        if (!$tenant->isLocaleEnabled($short) && null === $this->localeCodes->toBcp47($short, $tenant)) {
            throw new UnprocessableEntityHttpException(\sprintf('Unknown source locale "%s".', $code));
        }

        return $short;
    }

    private function requireTenant(): Tenant
    {
        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new UnprocessableEntityHttpException('No tenant context to resolve the value-source scope.');
        }

        return $tenant;
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function require(array $uriVariables): SyncBinding
    {
        $raw = $uriVariables['id'] ?? null;
        $id = $raw instanceof Uuid ? $raw : $this->parseId(\is_string($raw) ? $raw : '', 'id');

        $binding = $this->bindings->findById($id);
        if (!$binding instanceof SyncBinding) {
            throw new NotFoundHttpException('SyncBinding was not found.');
        }

        return $binding;
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
