<?php

declare(strict_types=1);

namespace App\Integration\Generic\Application\Sync;

use App\Catalog\Contracts\Integration\OutboundRecord;
use App\Catalog\Contracts\Integration\OutboundRecordReader;
use App\Catalog\Contracts\Integration\OutboundResultWriter;
use App\Integration\Generic\Application\Sleeper;
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
use App\Integration\Generic\Infrastructure\Http\RecordSelector;
use App\Integration\Generic\Infrastructure\Http\RemoteRequester;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use JsonException;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Throwable;

use const JSON_THROW_ON_ERROR;

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

    /**
     * In-body throttle handling (#2644, architektura §7.3): RPC APIs report
     * rate limits as HTTP 200 + error envelope, invisible to the transport
     * backoff. Retry the record with exponential sleeps; when the remote is
     * still throttling, ABORT the run (partial) instead of burning the
     * remaining records against a blocked token.
     */
    private const int MAX_THROTTLE_RETRIES = 5;
    private const int MAX_THROTTLE_SLEEP = 60;

    public function __construct(
        private FieldMappingRepositoryInterface $mappings,
        private PayloadBuilder $payloadBuilder,
        private OutboundBodyEncoder $bodyEncoder,
        private OutboundRecordReader $reader,
        private RemoteRequester $requester,
        private SyncRunRepositoryInterface $runs,
        private EntityManagerInterface $em,
        private TenantContext $tenantContext,
        private OutboundResultWriter $resultWriter,
        private RecordSelector $recordSelector,
        private SyncRunScope $runScope,
        private Sleeper $sleeper,
        private ManagerRegistry $managerRegistry,
    ) {
    }

    public function run(SyncBinding $binding, bool $dryRun = false): SyncRun
    {
        // Name the active connection so this run's own catalog writes (remote-id
        // capture) cannot re-enqueue its bindings via the on-change trigger.
        $this->runScope->enter($binding->getConnection()->getId());

        $run = new SyncRun($binding, SyncDirection::Outbound);
        $this->runs->save($run);
        $runId = $run->getId();

        try {
            return $this->doRun($run, $binding, $dryRun);
        } catch (Throwable $exception) {
            // #2722 — finalize before the transport retries: a run left
            // `running` would both dangle forever in the UI and let the retry
            // re-push every record from scratch (duplicate creates on the
            // remote for match-key-less WriteCreate endpoints).
            SyncRunFinalizer::markFailedAfterException($this->managerRegistry, $runId);

            throw $exception;
        } finally {
            $this->runScope->leave();
        }
    }

    private function doRun(SyncRun $run, SyncBinding $binding, bool $dryRun): SyncRun
    {
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
        $rateHint = $binding->getConnection()->getRateLimitHint();

        foreach ($this->reader->read($binding->getObjectTypeId(), $codes, $binding->getOutboundFilter(), $binding->getSourceChannel(), $binding->getSourceLocale()) as $record) {
            $continue = $this->push($run, $binding, $writeEndpoint, $record, $mappings, $matchCode, $action, $dryRun);
            $this->em->flush();

            if (!$continue) {
                // #2644 — the remote kept throttling through the backoff:
                // abort with what we have instead of burning the remaining
                // records against a blocked token.
                $run->markFinished(SyncRunStatus::Partial);
                $this->runs->save($run);
                $this->em->flush();

                return $run;
            }

            if (0 === ++$processed % self::CLEAR_EVERY) {
                $this->em->clear();
                $binding = $this->reload($bindingId);
                $run = $this->reloadRun($runId);
                $writeEndpoint = $binding->getWriteEndpoint() ?? $writeEndpoint;
                $mappings = $this->outboundMappings($binding, $writeEndpoint);
            }

            // #2644 — honour the connection's "Limit żądań" (requests/minute):
            // after every $rateHint pushes wait out the window instead of
            // tripping the remote's limiter.
            if (!$dryRun && null !== $rateHint && $rateHint > 0 && 0 === $processed % $rateHint) {
                $this->sleeper->sleep(60);
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
     * Pushes one record; returns false when the run must ABORT (remote kept
     * throttling through the backoff, #2644) — true otherwise, including
     * per-record failures (a bad record never stops the run).
     *
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
    ): bool {
        $mapped = $this->payloadBuilder->build($record->values, $mappings);
        if ([] === $mapped) {
            $run->recordSkipped();
            $this->log($run, SyncRecordAction::Skipped, null, $mapped, 'No outbound values to push.');

            return true;
        }

        // The endpoint's static envelope carries the constants an RPC API needs
        // (method name, inventory id…); mapped values are merged over it, so a
        // mapping always wins a key collision (#2634).
        /** @var array<string, mixed> $body */
        $body = array_replace_recursive($writeEndpoint->getRequestBodyTemplate() ?? [], $mapped);

        $matchValue = null !== $matchCode ? ($record->values[$matchCode] ?? null) : null;
        $url = $this->buildUrl($binding, $writeEndpoint, $matchValue);

        // Remote-id capture (#2636): when the id attribute already carries a
        // value, the mapped payload injects it and the remote treats the push
        // as an update — count it as such regardless of the endpoint role.
        if ($writeEndpoint->capturesRemoteId()) {
            $idAttribute = (string) $writeEndpoint->getResponseIdAttribute();
            $successAction = '' !== trim($record->values[$idAttribute] ?? '')
                ? SyncRecordAction::Updated
                : SyncRecordAction::Created;
        }

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

            return true;
        }

        $encoded = $this->bodyEncoder->encode($body, $writeEndpoint->getRequestFormat());
        $attempt = 0;
        while (true) {
            try {
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

                return true;
            }

            // #2644 — an in-body 2xx throttle (rate limit / blocked token) is
            // retryable: back off and re-send this record; if the remote keeps
            // throttling, give up on the whole run.
            $throttle = $response->isSuccessful()
                ? RemoteResponseInspector::throttleIn($response->body)
                : null;
            if (null === $throttle) {
                break;
            }
            if (++$attempt > self::MAX_THROTTLE_RETRIES) {
                $run->recordFailed();
                $this->log(
                    $run,
                    SyncRecordAction::Failed,
                    $matchValue,
                    $body,
                    'Throttled by remote — run aborted: '.$throttle,
                );

                return false;
            }
            $this->sleeper->sleep(min(2 ** $attempt, self::MAX_THROTTLE_SLEEP));
        }

        if ($response->isSuccessful()) {
            // A 2xx can still carry a per-record error in the body (#1886).
            $remoteError = RemoteResponseInspector::errorIn($response->body);
            if (null !== $remoteError) {
                $run->recordFailed();
                $this->log($run, SyncRecordAction::Failed, $matchValue, $body, 'Remote 2xx with error: '.$remoteError);

                return true;
            }

            $note = $this->captureRemoteId($writeEndpoint, $record, $response->body);

            SyncRecordAction::Updated === $successAction ? $run->recordUpdated() : $run->recordCreated();
            $this->log($run, $successAction, $matchValue, $body, $note);

            return true;
        }

        $run->recordFailed();
        $this->log($run, SyncRecordAction::Failed, $matchValue, $body, \sprintf('HTTP %d', $response->statusCode));

        return true;
    }

    /**
     * Extracts the remote record id from a successful write response and stores
     * it into the endpoint's configured PIM attribute (#2636). Returns a log
     * note (`remote_id=…`) or null when capture is off / nothing was found.
     */
    private function captureRemoteId(RemoteEndpoint $endpoint, OutboundRecord $record, string $responseBody): ?string
    {
        if (!$endpoint->capturesRemoteId()) {
            return null;
        }

        try {
            $decoded = json_decode($responseBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $remoteId = $this->recordSelector->value($decoded, $endpoint->getResponseIdSelector());
        if (!\is_scalar($remoteId) || '' === trim((string) $remoteId)) {
            return null;
        }
        $remoteId = (string) $remoteId;

        $this->resultWriter->writeValue(
            Uuid::fromString($record->objectId),
            (string) $endpoint->getResponseIdAttribute(),
            $remoteId,
        );

        return \sprintf('remote_id=%s', $remoteId);
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
