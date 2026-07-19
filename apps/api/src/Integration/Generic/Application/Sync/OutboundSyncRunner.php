<?php

declare(strict_types=1);

namespace App\Integration\Generic\Application\Sync;

use App\Catalog\Contracts\Integration\OutboundRecord;
use App\Catalog\Contracts\Integration\OutboundRecordReader;
use App\Integration\Generic\Domain\Entity\FieldMapping;
use App\Integration\Generic\Domain\Entity\RemoteEndpoint;
use App\Integration\Generic\Domain\Entity\SyncBinding;
use App\Integration\Generic\Domain\Entity\SyncRun;
use App\Integration\Generic\Domain\Entity\SyncRunLog;
use App\Integration\Generic\Domain\Enum\RemoteEndpointRole;
use App\Integration\Generic\Domain\Enum\SyncDirection;
use App\Integration\Generic\Domain\Enum\SyncRecordAction;
use App\Integration\Generic\Domain\Enum\SyncRunStatus;
use App\Integration\Generic\Domain\Exception\RemoteRequestFailedException;
use App\Integration\Generic\Domain\Exception\SsrfBlockedException;
use App\Integration\Generic\Domain\Repository\FieldMappingRepositoryInterface;
use App\Integration\Generic\Domain\Repository\SyncRunRepositoryInterface;
use App\Integration\Generic\Infrastructure\Http\RemoteRequester;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Runs one outbound (PIM → remote) sync of a {@see SyncBinding} (APIC-P3-06).
 *
 * Reads the ObjectType's objects serialised by the Export engine
 * ({@see OutboundRecordReader}), builds a 1:1 push body ({@see PayloadBuilder})
 * and POSTs/PUTs each through the SSRF-safe, backoff-retrying client
 * ({@see RemoteRequester}) to the binding's write endpoint. Per-record failures
 * are logged and counted (a bad record never fails the whole run); transport
 * dead-lettering is the message transport's job. Audited as a {@see SyncRun}
 * with per-record {@see SyncRunLog}.
 */
final readonly class OutboundSyncRunner
{
    /** Clear the unit of work every N pushed records (FrankenPHP worker hygiene). */
    private const int CLEAR_EVERY = 200;

    public function __construct(
        private FieldMappingRepositoryInterface $mappings,
        private PayloadBuilder $payloadBuilder,
        private OutboundBodyEncoder $bodyEncoder,
        private OutboundRecordReader $reader,
        private RemoteRequester $requester,
        private SyncRunRepositoryInterface $runs,
        private EntityManagerInterface $em,
        private TenantContext $tenantContext,
    ) {
    }

    public function run(SyncBinding $binding, bool $dryRun = false): SyncRun
    {
        $run = new SyncRun($binding, SyncDirection::Outbound);
        $this->runs->save($run);

        $writeEndpoint = $binding->getWriteEndpoint();
        if (null === $writeEndpoint) {
            $run->markFinished(SyncRunStatus::Failed);
            $this->runs->save($run);

            return $run;
        }

        $mappings = $this->outboundMappings($binding, $writeEndpoint);
        $matchCode = $this->matchCode($mappings);
        $codes = array_values(array_unique(array_map(static fn (FieldMapping $m): string => $m->getPimTarget(), $mappings)));

        $action = RemoteEndpointRole::WriteUpdate === $writeEndpoint->getRole()
            ? SyncRecordAction::Updated
            : SyncRecordAction::Created;

        $runId = $run->getId();
        $bindingId = $binding->getId();
        $processed = 0;

        // The reader yields one OutboundRecord (a DTO, not a managed entity) at a
        // time keyed by the captured objectTypeId + codes scalars, so the
        // generator survives the periodic clear. Each push persists a SyncRunLog
        // and the reader queries each object's values into the unit of work —
        // both accumulate across a 50k push, so clear every CLEAR_EVERY records
        // and reload the entities the loop keeps mutating.
        foreach ($this->reader->read($binding->getObjectTypeId(), $codes, $binding->getOutboundFilter()) as $record) {
            $this->push($run, $binding, $writeEndpoint, $record, $mappings, $matchCode, $action, $dryRun);
            $this->em->flush();

            if (0 === ++$processed % self::CLEAR_EVERY) {
                $this->em->clear();
                $binding = $this->reload($bindingId);
                $run = $this->reloadRun($runId);
                $writeEndpoint = $binding->getWriteEndpoint() ?? $writeEndpoint;
                $mappings = $this->outboundMappings($binding, $writeEndpoint);
            }
        }

        $run->markFinished();
        $this->runs->save($run);

        return $run;
    }

    /**
     * Outbound mappings scoped to the write endpoint: endpoint-less mappings
     * apply everywhere, endpoint-scoped ones only to their own operation (#2634
     * — RPC APIs shape the payload per method).
     *
     * @return list<FieldMapping>
     */
    private function outboundMappings(SyncBinding $binding, RemoteEndpoint $writeEndpoint): array
    {
        return array_values(array_filter(
            $this->mappings->findByConnection($binding->getConnection()),
            static fn (FieldMapping $m): bool => $m->getDirection()->appliesOutbound()
                && $m->appliesToEndpoint($writeEndpoint->getId()),
        ));
    }

    private function reload(Uuid $bindingId): SyncBinding
    {
        $binding = $this->em->find(SyncBinding::class, $bindingId->toRfc4122());
        if (!$binding instanceof SyncBinding) {
            throw new RuntimeException('Sync binding vanished mid-run.');
        }

        // The clear detached the tenant; re-bind the managed one so the next
        // batch's SyncRunLog rows still stamp tenant_id (TenantFilter).
        $tenant = $binding->getTenant();
        if ($tenant instanceof Tenant) {
            $this->tenantContext->set($tenant);
        }

        return $binding;
    }

    private function reloadRun(Uuid $runId): SyncRun
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof SyncRun) {
            throw new RuntimeException('Sync run vanished mid-run.');
        }

        return $run;
    }

    /**
     * @param list<FieldMapping> $mappings
     */
    private function push(
        SyncRun $run,
        SyncBinding $binding,
        RemoteEndpoint $writeEndpoint,
        OutboundRecord $record,
        array $mappings,
        ?string $matchCode,
        SyncRecordAction $successAction,
        bool $dryRun = false,
    ): void {
        $mapped = $this->payloadBuilder->build($record->values, $mappings);
        if ([] === $mapped) {
            $run->recordSkipped();
            $this->log($run, SyncRecordAction::Skipped, null, $mapped, 'No outbound values to push.');

            return;
        }

        // The endpoint's static envelope carries the constants an RPC API needs
        // (method name, inventory id…); mapped values are merged over it, so a
        // mapping always wins a key collision (#2634).
        /** @var array<string, mixed> $body */
        $body = array_replace_recursive($writeEndpoint->getRequestBodyTemplate() ?? [], $mapped);

        $matchValue = null !== $matchCode ? ($record->values[$matchCode] ?? null) : null;
        $url = $this->buildUrl($binding, $writeEndpoint, $matchValue);

        if ($dryRun) {
            // Preview only — record what would be sent without calling the remote.
            $run->recordSkipped();
            $this->log(
                $run,
                SyncRecordAction::Skipped,
                $matchValue,
                $body,
                \sprintf('DRY RUN — would %s %s', $writeEndpoint->getHttpMethod(), $url),
            );

            return;
        }

        try {
            $encoded = $this->bodyEncoder->encode($body, $writeEndpoint->getRequestFormat());
            $response = $this->requester->request(
                $binding->getConnection(),
                $writeEndpoint->getHttpMethod(),
                $url,
                [],
                $encoded->headers,
                $encoded->body,
            );
        } catch (SsrfBlockedException|RemoteRequestFailedException $exception) {
            $run->recordFailed();
            $this->log($run, SyncRecordAction::Failed, $matchValue, $body, $exception->getMessage());

            return;
        }

        if ($response->isSuccessful()) {
            // A 2xx can still carry a per-record error in the body (#1886).
            $remoteError = RemoteResponseInspector::errorIn($response->body);
            if (null !== $remoteError) {
                $run->recordFailed();
                $this->log($run, SyncRecordAction::Failed, $matchValue, $body, 'Remote 2xx with error: '.$remoteError);

                return;
            }

            SyncRecordAction::Updated === $successAction ? $run->recordUpdated() : $run->recordCreated();
            $this->log($run, $successAction, $matchValue, $body, null);

            return;
        }

        $run->recordFailed();
        $this->log($run, SyncRecordAction::Failed, $matchValue, $body, \sprintf('HTTP %d', $response->statusCode));
    }

    /**
     * @param list<FieldMapping> $mappings
     */
    private function matchCode(array $mappings): ?string
    {
        foreach ($mappings as $mapping) {
            if ($mapping->isMatchKey()) {
                return $mapping->getPimTarget();
            }
        }

        return null;
    }

    /**
     * Resolves the write URL, substituting any `{token}` placeholder in the
     * path with the match value (e.g. `PUT /products/{id}`).
     */
    private function buildUrl(SyncBinding $binding, RemoteEndpoint $endpoint, ?string $matchValue): string
    {
        $path = $endpoint->getPathTemplate();
        if (null !== $matchValue) {
            $path = (string) preg_replace('/\{[^}]+\}/', rawurlencode($matchValue), $path);
        }

        $path = ltrim($path, '/');
        $base = rtrim($binding->getConnection()->getBaseUrl(), '/');

        return '' === $path ? $base : $base.'/'.$path;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function log(SyncRun $run, SyncRecordAction $action, ?string $matchKey, array $fields, ?string $message): void
    {
        $log = new SyncRunLog($run, $action);
        $log->setMatchKey($matchKey);
        $log->setFields($fields);
        $log->setMessage($message);
        $this->em->persist($log);
    }
}
