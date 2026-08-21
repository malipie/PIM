<?php

declare(strict_types=1);

namespace App\Import\Application\Handler;

use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Import\Application\Service\AttributeAutoCreator;
use App\Import\Application\Service\ImportCategoryOps;
use App\Import\Application\Service\ImportChunkSizer;
use App\Import\Application\Service\ImportColumnGrammar;
use App\Import\Application\Service\ImportObjectCreator;
use App\Import\Application\Service\ImportProgressPublisher;
use App\Import\Application\Service\ImportRowCells;
use App\Import\Application\Service\ImportRowCountEstimator;
use App\Import\Application\Service\ImportRowDecision;
use App\Import\Application\Service\ImportRowReader;
use App\Import\Application\Service\ImportUndoLogger;
use App\Import\Application\Service\ImportValidationService;
use App\Import\Application\Service\Media\AssetUrlResolver;
use App\Import\Application\Service\ObjectResolver;
use App\Import\Application\Service\OptionAutoCreator;
use App\Import\Application\Service\RelationImportStep;
use App\Import\Domain\Entity\ImportLog;
use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Enum\ImportErrorType;
use App\Import\Domain\Enum\ImportImageSource;
use App\Import\Domain\Enum\ImportLogLevel;
use App\Import\Domain\Enum\ImportSessionStatus;
use App\Import\Domain\Message\ImageDownloadJob;
use App\Import\Domain\Message\ImageDownloadMessage;
use App\Import\Domain\Message\ImportRunMessage;
use App\Import\Domain\Repository\ImportSessionRepositoryInterface;
use App\Import\Domain\ReservedMappingTarget;
use App\Import\Domain\SystemColumn;
use App\Import\Domain\ValueObject\ResolvedImportValue;
use App\Import\Domain\ValueObject\ValidationError;
use App\Shared\Application\AbstractBatchHandler;
use App\Shared\Application\BulkOperationInProgressException;
use App\Shared\Application\BulkOperationLock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Messenger\Stamp\TenantStamp;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use LogicException;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

use const PATHINFO_EXTENSION;

/**
 * IMP-04 (#445) — async (and inline-for-small-files) import execution.
 *
 * Streams rows from the uploaded file (downloaded from MinIO into a temp
 * path), runs the IMP-03 row validation, and persists every clean row
 * via {@see ImportObjectCreator}. Per-row findings land in
 * {@see ImportLog} so the post-import CSV report (IMP-05) and the
 * Mercure progress stream see the same data.
 *
 * Memory contract (R-25): batches of 200 rows funnel through
 * {@see flushAndClear()} so the worker stays under 256 MiB on a 5k-row
 * import; the long-lived `ImportSession` row is re-merged into the EM
 * after each clear so counters stay current without straggling
 * proxy state.
 */
/**
 * #2815 — dispatched on `messenger.bus.long_running`, i.e. handled WITHOUT the
 * blanket `doctrine_transaction` middleware, because this handler manages its
 * own boundaries: it commits per chunk to bound memory and so that a resumed
 * run picks up from a checkpoint that actually survived. Under the wrapping
 * transaction none of its writes were visible until the run ended, and a crash
 * discarded all of them — see the bus definition in
 * config/packages/messenger.yaml.
 *
 * Deliberately NOT restricted to that bus with `#[AsMessageHandler(bus: ...)]`:
 * the buses run with `allow_no_handlers`, so a dispatch that ever went to the
 * default bus would be dropped in silence rather than failing loudly. Staying
 * registered on both buses makes the worst case "runs with the old transaction
 * semantics" instead of "does not run at all"; the bus is chosen at the two
 * dispatch sites (config/services.yaml).
 */
#[AsMessageHandler]
final class ImportRunHandler extends AbstractBatchHandler
{
    /** IMP2-1.12 — image-download jobs per dispatched media batch. */
    private const int MEDIA_BATCH_SIZE = 50;

    /** IMP2-2.6 — id batch size for the async attributes_indexed rebuild dispatch. */
    private const int REBUILD_DISPATCH_BATCH = 500;

    /**
     * IMP2-2.7 (#1483) — minimum processed rows before the Allowed-Errors ratio is
     * evaluated, so a single bad row in the first chunk (1/1 = 100%) cannot abort
     * a run whose profile set a low threshold.
     */
    private const int ALLOWED_ERRORS_MIN_ROWS = 20;

    /**
     * Hard cap on per-run ImportLog rows. A pathological file (e.g. a stale
     * export whose channel/locale-suffixed columns no longer match the catalog)
     * raises dozens of row-level errors per row; persisting one ImportLog entity
     * each made the row phase grow O(total errors) and OOM the 256 MiB worker
     * (each persisted entity retains its message + raw-row snippet past the
     * per-chunk EntityManager::clear()). Beyond the cap we count suppressed
     * messages and emit a single summary row (Akeneo's "first N + tail count"
     * pattern) — 5 000 logged messages already exceeds any human-actionable
     * report, so nothing diagnostic is lost.
     */
    public const int MAX_PERSISTED_IMPORT_LOGS = 5_000;

    /** Per-run ImportLog accounting for {@see MAX_PERSISTED_IMPORT_LOGS}. */
    private int $importLogsPersisted = 0;
    private int $importLogsSuppressed = 0;

    /**
     * IMP2-2.6 — RFC4122 ids of objects this run created or actually changed,
     * collected for the end-of-run async rebuild + reindex. Strings (not
     * entities) so they survive the per-chunk EntityManager::clear().
     *
     * @var list<string>
     */
    private array $bulkTouchedIds = [];

    /**
     * IMP2-2.6 — peak `memory_get_peak_usage(true)` reached by the end of the
     * row phase, i.e. the figure the production import worker actually pays:
     * read → write → flush/clear, BEFORE the attributes_indexed rebuild +
     * Meili reindex (those run in a separate worker in prod, the `async`/`import`
     * transport). Recorded here so the memory benchmark can assert on the import
     * worker in isolation rather than on the dev/test artifact where the sync
     * transport runs the rebuild inline in the same process. Null until a run
     * reaches the dispatch point.
     */
    private ?int $rowPhasePeakBytes = null;

    /**
     * IMP2-2.6 — Mercure progress throttle. The batch path no longer publishes a
     * `row_processed` event per row (50k rows = 50k HTTP POSTs to the hub);
     * instead {@see publishProgress()} emits a `progress` snapshot no more often
     * than every ~1% of the file. `$lastProgressAt` is the row count at the last
     * emitted snapshot; `$lastProcessedSku` rides along as the snapshot's
     * `current_sku`. Both reset per run.
     */
    private int $lastProgressAt = 0;

    private ?string $lastProcessedSku = null;

    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly ImportSessionRepositoryInterface $sessions,
        private readonly ImportRowReader $rowReader,
        private readonly ImportValidationService $validator,
        private readonly ImportObjectCreator $creator,
        private readonly OptionAutoCreator $optionAutoCreator,
        private readonly AttributeAutoCreator $attributeAutoCreator,
        private readonly ObjectResolver $objectResolver,
        private readonly RelationImportStep $relationStep,
        private readonly ImportUndoLogger $undoLogger,
        private readonly ImportColumnGrammar $columnGrammar,
        private readonly \App\Catalog\Application\BatchValueWriter $valueWriter,
        private readonly \App\Catalog\Application\CrossFieldRulesValidator $crossFieldRules,
        private readonly \App\Catalog\Domain\Repository\AttributeRepositoryInterface $attributeRepository,
        private readonly \App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface $catalogObjects,
        private readonly \App\Asset\Domain\Repository\AssetRepositoryInterface $assets,
        private readonly ImportProgressPublisher $progressPublisher,
        private readonly TenantContext $tenantContext,
        private readonly FilesystemOperator $importsStorage,
        private readonly BulkOperationLock $bulkLock,
        private readonly ManagerRegistry $managerRegistry,
        private readonly AssetUrlResolver $assetUrlResolver,
        private readonly MessageBusInterface $messageBus,
        private readonly \App\Catalog\Application\BulkContext $bulkContext,
        private readonly ImportRowCells $rowCells,
        private readonly ImportCategoryOps $categoryOps,
        private readonly ImportRowCountEstimator $rowCountEstimator,
        int $batchSize = 200,
    ) {
        parent::__construct($entityManager, $batchSize);
    }

    public function __invoke(ImportRunMessage $message): void
    {
        $session = $this->sessions->findById($message->importSessionId);
        if (!$session instanceof ImportSession) {
            return;
        }

        $tenant = $session->getTenant();
        if (!$tenant instanceof Tenant) {
            $session->markFailed('Import session has no tenant assignment.');
            $this->sessions->save($session);

            return;
        }

        $this->tenantContext->set($tenant);

        try {
            $this->run($session);
        } catch (BulkOperationInProgressException $exception) {
            // PROD-05 — Messenger entry point: re-throw as recoverable so
            // the queue retries with backoff instead of dead-lettering.
            // The synchronous controller path in StartImportController
            // catches the same domain exception and returns HTTP 409.
            throw new RecoverableMessageHandlingException(
                $exception->getMessage().' Will retry.',
                previous: $exception,
            );
        }
    }

    /**
     * Drives the chunked persistence loop. Public so the synchronous
     * `<50 rows` path on the start endpoint can call into the same logic
     * without re-routing through Messenger.
     */
    public function run(ImportSession $session): void
    {
        // #1718 / #1728 — drop any option/attribute mint cache from a previous
        // run on this long-lived worker before processing a fresh file.
        $this->optionAutoCreator->reset();
        $this->attributeAutoCreator->reset();

        // Streaming + per-row Doctrine flushes scale with file size.
        // A 100-row XLSX easily exceeds FrankenPHP's default 30s HTTP
        // budget (max_execution_time is 0 in CLI but the worker resets
        // it to 30 for each request). Without this opt-out the import
        // handler gets killed mid-loop, the response still returns 200
        // because the headers were already flushed, but the session
        // stays `pending` forever and 0 import_logs are written.
        set_time_limit(0);

        $tenant = $session->getTenant();
        if (!$tenant instanceof Tenant) {
            $session->markFailed('Import session has no tenant assignment.');
            $this->sessions->save($session);

            return;
        }

        // IMP2-2.3 — a message redelivered for a session the operator PAUSED
        // (and did not resume) must NOT auto-resume. Resume re-dispatches with
        // the status already flipped to `running`, so a still-`paused` status
        // here means "leave it paused" — drop the message without running.
        if (ImportSessionStatus::Paused === $session->getStatus()) {
            return;
        }

        // PROD-05 — at-most-one bulk job per tenant. Non-blocking acquire;
        // on collision raise a domain exception so the caller decides
        // (controller -> 409 Conflict, async handler -> recoverable
        // retry). ImportSession status stays `pending` so the operator
        // sees the job is waiting, not dropped.
        $lock = $this->bulkLock->acquire($tenant);
        if (null === $lock) {
            throw new BulkOperationInProgressException($tenant);
        }

        try {
            $session->markRunning();
            $this->sessions->save($session);
        } catch (LogicException $exception) {
            // Already running / paused / failed — bail without state churn.
            $lock->release();

            return;
        }

        // IMP2-1.8: the cross-object link buffer accumulates across all chunks
        // of THIS run; reset so a long-lived worker never carries stale tuples.
        $this->relationStep->reset();
        $this->undoLogger->reset();
        // IMP2-2.6 — run the whole import under BulkContext so the per-flush
        // sync AttributesIndexedSyncListener + CatalogIndexSubscriber stay out
        // of the way (no rebuild/Meili work on every chunk flush). The touched
        // objects are rebuilt + reindexed once, async, after the row phase.
        // Reset in finally — a long-lived worker must not leak the flag.
        $this->bulkContext->setBulk(true, $session->getId());
        $this->bulkTouchedIds = [];
        $this->importLogsPersisted = 0;
        $this->importLogsSuppressed = 0;
        $this->rowPhasePeakBytes = null;
        $this->lastProgressAt = 0;
        $this->lastProcessedSku = null;

        // IMP2-2.3 — resume point: a prior (paused/crashed) run left a checkpoint.
        // Rows at/below it are already written, so we skip their writes (still
        // re-buffering their cross-object links for pass 2) and keep the
        // persisted counters. 0 on a fresh run.
        $resumeFrom = $session->getCheckpointOffset() ?? 0;

        $sourcePath = null;
        try {
            $columnMapping = $this->resolveColumnMapping($session);
            $attributesByCode = $this->withCrossFieldRuleAttributes(
                $this->validator->loadAttributesByCode($tenant, $columnMapping),
                $session,
                $tenant,
            );
            $sourcePath = $this->stageSourceFile($session, $tenant);
            // #2815 — publish the size of the job BEFORE working through it.
            // total_rows was written only after the row loop, so throughout the
            // run — the only time it is of any use — the sessions list showed
            // "—" next to a job that had been going for twenty minutes.
            $this->recordRowCountEstimate($session, $sourcePath);

            $skuSeenInFile = [];
            // IMP2-1.9 (item 2) — running file-wide set of identifier values
            // already claimed, keyed by attributeCode, so a duplicate identifier
            // across chunks is caught as a skip before the DB unique index.
            $identifierSeenInFile = [];
            // IMP2-1.12 — image-download jobs (Asset cells carrying URLs) are
            // buffered across ALL chunks as plain DTOs (survive clear) and
            // dispatched AFTER the row phase: dispatching mid-loop would let a
            // sync-transport ImageDownloadHandler clear the EM under the row
            // loop's feet.
            /** @var list<ImageDownloadJob> $mediaJobs */
            $mediaJobs = [];
            $processed = 0;
            $totalRows = 0;
            /** @var list<array{rowNumber: int, cells: array<string, string|null>}> $buffer */
            $buffer = [];

            // IMP2-1.4 (#1466): rows are buffered per chunk so the resolver
            // and the value writer pay one query per chunk (resolveMany +
            // primeChunk) instead of one per row. flushAndClear() detaches
            // everything, so the re-merge block below replays the lookups.
            $chunkRows = ImportChunkSizer::rowsPerChunk(\count($columnMapping), $this->batchSize);

            foreach ($this->rowReader->read($sourcePath) as $rowNumber => $cells) {
                ++$totalRows;
                $buffer[] = ['rowNumber' => $rowNumber, 'cells' => $cells];

                // #2813 — flush by working-set size, not by row count. See
                // TARGET_VALUES_PER_CHUNK for why rows are the wrong unit here.
                if (\count($buffer) < $chunkRows) {
                    continue;
                }

                $chunkLastRow = $buffer[array_key_last($buffer)]['rowNumber'];
                $processed += $this->processChunk($session, $tenant, $buffer, $columnMapping, $attributesByCode, $skuSeenInFile, $identifierSeenInFile, $mediaJobs, $resumeFrom);
                $buffer = [];

                // IMP2-2.3 — record the checkpoint on the still-managed session
                // BEFORE the flush, so the chunk's rows AND the checkpoint commit
                // in the SAME transaction. A crash between commit and a separate
                // checkpoint save would otherwise re-process committed rows.
                $session->recordCheckpoint($chunkLastRow, 'rows');
                // #2815 — the checkpoint exists for resume, not for reporting:
                // it holds a file offset, is cleared on completion, and is not
                // exposed. Progress rides the SAME flush so the durable state
                // moves with the run instead of only at its end.
                $session->recordProgress($processed);
                $this->flushAndClear();
                // After clear() every previously-loaded entity is detached —
                // re-merge by reloading the session and tenant, replaying the
                // attribute lookup and resetting TenantContext.
                $session = $this->refreshSession($session);
                $tenant = $session->getTenant();
                if (!$tenant instanceof Tenant) {
                    throw new RuntimeException(\sprintf(
                        'Import session "%s" lost its tenant assignment mid-run.',
                        $session->getId()->toRfc4122(),
                    ));
                }
                $this->tenantContext->set($tenant);

                // Extend the bulk lock so a long import outlives the TTL, then
                // honour a pause/cancel the endpoint persisted during this chunk
                // (the refreshed session carries the just-written status).
                $lock->refresh();
                if ($this->haltRequested($session)) {
                    $this->publishProgress($session, $processed, true);

                    return;
                }

                $attributesByCode = $this->withCrossFieldRuleAttributes(
                    $this->validator->loadAttributesByCode($tenant, $columnMapping),
                    $session,
                    $tenant,
                );
                // Throttled: at most one snapshot per ~1% of the file (force=false).
                $this->publishProgress($session, $processed);

                // IMP2-2.7 (#1483) — Allowed-Errors guardrail (per full chunk).
                if ($this->abortIfErrorRateExceeded($session, $processed, $tenant)) {
                    return;
                }
            }

            if ([] !== $buffer) {
                $chunkLastRow = $buffer[array_key_last($buffer)]['rowNumber'];
                $processed += $this->processChunk($session, $tenant, $buffer, $columnMapping, $attributesByCode, $skuSeenInFile, $identifierSeenInFile, $mediaJobs, $resumeFrom);
                $session->recordCheckpoint($chunkLastRow, 'rows');
                $session->recordProgress($processed);
                $this->flushAndClear();
                $session = $this->refreshSession($session);
                $lock->refresh();
                if ($this->haltRequested($session)) {
                    $this->publishProgress($session, $processed, true);

                    return;
                }
                // IMP2-2.7 (#1483) — also evaluate after the tail chunk so a file
                // smaller than one batch (< chunk size) still aborts on errors.
                if ($this->abortIfErrorRateExceeded($session, $processed, $tenant)) {
                    return;
                }
            }

            // IMP2-1.8 — pass 2: wire buffered cross-object links (parent →
            // master) now that EVERY object exists, so variant rows may appear
            // before or after their master. resolve() flush+clears in chunks.
            if ($this->relationStep->hasWork()) {
                // IMP2-2.3 — mark the relation phase: a crash/redelivery here
                // resumes with offset = totalRows, so every row is re-buffered
                // (links only) and the idempotent pass 2 re-runs cleanly.
                $session->recordCheckpoint($totalRows, 'relations');
                $this->sessions->save($session);
                $linkTenant = $session->getTenant();
                if ($linkTenant instanceof Tenant) {
                    $linkErrors = $this->relationStep->resolve($this->requireTargetObjectType($session)->getKind(), $linkTenant);
                    $session = $this->refreshSession($session);
                    // Extend the lock past the (potentially long) relation pass.
                    $lock->refresh();
                    foreach ($linkErrors as $error) {
                        $session->incrementError();
                        if (!$this->importLogBudgetAvailable()) {
                            continue;
                        }
                        $this->entityManager->persist(new ImportLog(
                            importSession: $session,
                            rowNumber: $error->rowNumber,
                            level: $error->level,
                            message: $error->message,
                            sku: $error->sku,
                            errorType: $error->errorType->value,
                            columnName: $error->columnName,
                            columnValue: $error->columnValue,
                        ));
                    }
                }
            }

            // IMP2-2.3 — a pause/cancel that landed during pass 2 stops here;
            // the checkpoint (relations) lets a resume re-run the idempotent
            // link pass without touching already-written rows.
            if ($this->haltRequested($session)) {
                $this->publishProgress($session, $processed, true);

                return;
            }

            // The exact count, replacing the estimate recorded before the loop.
            $session->setTotalRows($totalRows);
            $session->recordProgress($processed);
            $this->persistSuppressedImportLogSummary($session);
            $this->sessions->save($session);

            // IMP2-1.12 — dispatch the buffered media batches now (after the
            // row phase + relation links), then defer finalization: the last
            // download batch to finish finalizes the session, or this handler
            // does it right here when there is no media / sync already drained
            // the batches.
            $session = $this->dispatchMediaBatches($session, $mediaJobs);

            // IMP2-2.3 — a pause/cancel may have landed during the relation pass
            // or media dispatch; dispatchMediaBatches can return a stale session
            // (no media jobs), so read the COMMITTED status straight from the DB
            // and never force a paused/cancelled session into a terminal state.
            if ($this->persistedStatusHalts($session)) {
                $this->publishProgress($session, $processed, true);

                return;
            }

            $session->markRowPhaseComplete();
            if ($session->canFinalizeMedia() && ImportSessionStatus::Running === $session->getStatus()) {
                // Fully processed — drop the resume marker only now that we are
                // committing the terminal state.
                $session->clearCheckpoint();
                $session->markCompleted();
                $this->sessions->save($session);
                $this->progressPublisher->completed($session);
            } else {
                $this->sessions->save($session);
                $this->publishProgress($session, $processed, true);
            }

            // IMP2-2.6 — the row phase ran under BulkContext (no per-flush
            // rebuild/index). Rebuild attributes_indexed + reindex Meili for the
            // touched objects asynchronously now (eventual consistency, per the
            // >1000-objects async rule). Dispatched at end-of-run, never per
            // chunk: in dev/test the async transport is sync, so a mid-loop
            // inline rebuild would clear the EM under the row loop.
            $this->rowPhasePeakBytes = \memory_get_peak_usage(true);
            $this->dispatchAttributesRebuild($tenant);
        } catch (Throwable $exception) {
            // IMP2-1.9 — a throw from flush() leaves the EM CLOSED, so the old
            // path (findById + save on the same manager) threw again and left
            // the session stuck in `running`. Reset the manager and record the
            // outcome on a fresh one. A connection-level fault is systemic →
            // `failed`; anything else that slipped the pre-flush checks degrades
            // the session to `partial` so the rows committed by earlier chunks
            // are not lost (full per-row replay of the failed chunk is deferred
            // to IMP2-2.3 — it needs the same EM-lifecycle rework as resume).
            $this->recordOutcomeAfterException($session->getId(), $exception);
            throw $exception;
        } finally {
            if (null !== $sourcePath && file_exists($sourcePath)) {
                @unlink($sourcePath);
            }
            // IMP2-2.6 — clear the bulk flag for the next message on this worker.
            $this->bulkContext->reset();
            $lock->release();
        }
    }

    /**
     * IMP2-2.6 — peak bytes the import worker reached by the end of the row
     * phase (before the offloaded rebuild). Null until a run reaches dispatch.
     * Used by the memory benchmark to measure the prod import worker in
     * isolation from the dev/test sync-transport inline rebuild.
     */
    public function rowPhasePeakBytes(): ?int
    {
        return $this->rowPhasePeakBytes;
    }

    /**
     * True while the run is still under {@see MAX_PERSISTED_IMPORT_LOGS}. Each
     * call that returns true reserves one slot; once the cap is hit every later
     * call counts a suppressed message and returns false, so the row phase stays
     * O(cap) instead of O(total errors). {@see persistSuppressedImportLogSummary}
     * surfaces the suppressed tail to the operator.
     */
    private function importLogBudgetAvailable(): bool
    {
        if ($this->importLogsPersisted >= self::MAX_PERSISTED_IMPORT_LOGS) {
            ++$this->importLogsSuppressed;

            return false;
        }
        ++$this->importLogsPersisted;

        return true;
    }

    /**
     * Emit one summary ImportLog when the per-run cap suppressed messages, so a
     * truncated report never silently hides that more rows had issues.
     */
    private function persistSuppressedImportLogSummary(ImportSession $session): void
    {
        if (0 === $this->importLogsSuppressed) {
            return;
        }
        // The row/relation phases clear the EM, leaving TenantContext holding a
        // detached Tenant; re-attach a managed one so the TenantAssignmentListener
        // can stamp this summary row (mirrors the per-chunk reattach).
        $tenant = $session->getTenant();
        if ($tenant instanceof Tenant) {
            $managed = $this->entityManager->find(Tenant::class, $tenant->getId()->toRfc4122());
            if ($managed instanceof Tenant) {
                $this->tenantContext->set($managed);
            }
        }
        $this->entityManager->persist(new ImportLog(
            importSession: $session,
            rowNumber: 0,
            level: ImportLogLevel::Warning,
            message: \sprintf(
                '%d further messages were suppressed — this import logged the first %d issues (per-run cap). Fix the reported problems and re-run to see the rest.',
                $this->importLogsSuppressed,
                self::MAX_PERSISTED_IMPORT_LOGS,
            ),
            sku: null,
            errorType: ImportErrorType::InvalidValue->value,
            columnName: null,
            columnValue: null,
        ));
    }

    /**
     * #2815 — record how many rows the run is about to work through, as soon as
     * the file is on local disk and before the first one is read.
     *
     * Only fills a gap: a session that already carries a count (a resume, or a
     * caller that knew the size up front) is left alone, and a file that gives
     * no cheap answer leaves the column NULL rather than carrying a guess. The
     * exact count overwrites this when the loop ends.
     */
    private function recordRowCountEstimate(ImportSession $session, string $sourcePath): void
    {
        if (null !== $session->getTotalRows()) {
            return;
        }

        $estimate = $this->rowCountEstimator->estimate($sourcePath);
        if (null === $estimate) {
            return;
        }

        $session->setTotalRows($estimate);
        $this->sessions->save($session);
    }

    /**
     * IMP2-2.6 — publish a Mercure `progress` snapshot, throttled. The batch path
     * dropped the per-row `row_processed` event (50k rows = 50k hub POSTs); this
     * emits a `progress` snapshot at most once per ~1% of the file, so a 50k
     * import lands ~100 hub messages instead of 50k. `$force` always emits — used
     * for the terminal / halt snapshots so the UI never misses the final state.
     * `current_sku` carries the last processed row's SKU for the live view.
     */
    private function publishProgress(ImportSession $session, int $processed, bool $force = false): void
    {
        $total = $session->getTotalRows() ?? 0;
        $every = max($this->batchSize, (int) ceil($total / 100));
        if (!$force && ($processed - $this->lastProgressAt) < $every) {
            return;
        }
        $this->lastProgressAt = $processed;
        $this->progressPublisher->progress($session, $processed, $this->lastProcessedSku);
    }

    /**
     * IMP2-2.7 (#1483) — true when the profile set an Allowed-Errors threshold and
     * the run's error ratio has crossed it (after a minimum sample). Integer math:
     * `errorCount/processed*100 > threshold` ⟺ `errorCount*100 > threshold*processed`.
     */
    private function errorRateExceeded(ImportSession $session, int $processed): bool
    {
        $threshold = $session->getProfile()?->getAllowedErrorsPct();
        if (null === $threshold || $processed < self::ALLOWED_ERRORS_MIN_ROWS) {
            return false;
        }

        return $session->getErrorCount() * 100 > $threshold * $processed;
    }

    /**
     * IMP2-2.7 (#1483) — if the Allowed-Errors ratio is crossed, index the rows
     * already written, mark the session Failed (rows stay for rollback via
     * IMP2-2.4), publish the terminal snapshot, and return true so the caller
     * bails. Returns false to continue the run.
     */
    private function abortIfErrorRateExceeded(ImportSession $session, int $processed, Tenant $tenant): bool
    {
        if (!$this->errorRateExceeded($session, $processed)) {
            return false;
        }

        $this->rowPhasePeakBytes = \memory_get_peak_usage(true);
        // Read the counters off the still-managed session BEFORE the rebuild
        // dispatch: on a sync transport the inline RebuildAttributesIndexedHandler
        // (an AbstractBatchHandler) flush+clears the EM, detaching $session and
        // its tenant/profile/targetObjectType proxies.
        $threshold = $session->getProfile()?->getAllowedErrorsPct() ?? 0;
        $errorCount = $session->getErrorCount();
        $this->dispatchAttributesRebuild($tenant);
        // EM was cleared by the inline rebuild: reload the session so the status
        // mutation + save flush against a MANAGED entity (mirrors
        // ImportRollbackService finalize), never re-persisting detached proxies.
        $session = $this->refreshSession($session);
        $session->markFailed(\sprintf(
            'Przerwano import: odsetek błędnych wierszy przekroczył próg %d%% (%d błędów / %d wierszy).',
            $threshold,
            $errorCount,
            $processed,
        ));
        $this->sessions->save($session);
        $this->publishProgress($session, $processed, true);

        return true;
    }

    /**
     * IMP2-2.6 — dispatch the async attributes_indexed rebuild + Meilisearch
     * reindex for every object the row phase created or actually changed, in id
     * batches so a 50k import never ships one giant message. The TenantStamp
     * lets the worker rebind the tenant before {@see RebuildAttributesIndexedHandler}
     * runs. Cleared after dispatch so a re-run starts empty.
     */
    private function dispatchAttributesRebuild(Tenant $tenant): void
    {
        if ([] === $this->bulkTouchedIds) {
            return;
        }

        foreach (array_chunk($this->bulkTouchedIds, self::REBUILD_DISPATCH_BATCH) as $batch) {
            $this->messageBus->dispatch(
                new \App\Catalog\Application\Message\ObjectValuesChangedMessage($batch),
                [new TenantStamp($tenant->getId())],
            );
        }

        $this->bulkTouchedIds = [];
    }

    /**
     * @return array<string, string>
     */
    private function resolveColumnMapping(ImportSession $session): array
    {
        $sessionMapping = $session->getColumnMapping();
        if ([] !== $sessionMapping) {
            return $sessionMapping;
        }

        $profile = $session->getProfile();
        if (null === $profile) {
            return [];
        }

        return $profile->getColumnMapping();
    }

    /**
     * Resolves every mapped, non-system column into a {@see ResolvedImportValue}
     * carrying the attribute code + the locale parsed from its dotted
     * header. A list (not a flat map) keeps several localised columns that
     * target the same attribute (`name.pl`, `name.en`) distinct (#1130).
     *
     * @param array<string, string|null> $cells
     * @param array<string, string>      $columnMapping
     *
     * @return list<ResolvedImportValue>
     */
    private function materialiseValues(array $cells, array $columnMapping, Tenant $tenant): array
    {
        $out = [];
        foreach ($columnMapping as $columnHeader => $attributeCode) {
            if (SystemColumn::isSystem($columnHeader)) {
                continue;
            }
            if ('' === $attributeCode || ReservedMappingTarget::isReserved($attributeCode)) {
                continue;
            }
            $parsed = $this->columnGrammar->parse($columnHeader, $tenant);
            if (null !== $parsed->unknownSuffix) {
                // The validator already flagged the column; never write a
                // bogus locale row (pre-1.6 silent corruption).
                continue;
            }
            $out[] = new ResolvedImportValue(
                attributeCode: $attributeCode,
                locale: $parsed->locale,
                rawValue: $cells[$columnHeader] ?? null,
                channelId: $parsed->channelId,
            );
        }

        return $out;
    }

    /**
     * IMP2-1.9 (item 2) — flag rows whose identifier-attribute value collides,
     * BEFORE they reach the flush. A value already used by a DIFFERENT object
     * in the catalog (queried once per chunk via the trigger-maintained
     * denormalised columns) blocks the row with an Error; a value that already
     * appeared earlier in the file is a non-blocking skip (D1). Mutates the
     * prepared rows in place.
     *
     * @param list<array{rowNumber: int, cells: array<string, string|null>, sku: ?string, errors: list<ValidationError>, rowOk: bool, resolvedValues: list<ResolvedImportValue>, matchKey: string, duplicateInFile: bool}> $prepared
     * @param array<string, Attribute>                                                                                                                                                                                     $attributesByCode
     * @param array<string, CatalogObject>                                                                                                                                                                                 $existingByKey
     * @param array<string, array<string, int>>                                                                                                                                                                            $identifierSeenInFile
     */
    private function precheckIdentifiers(
        array &$prepared,
        array $attributesByCode,
        array $existingByKey,
        ImportSession $session,
        Tenant $tenant,
        array &$identifierSeenInFile,
    ): void {
        $identifierAttributes = [];
        foreach ($attributesByCode as $code => $attribute) {
            if (AttributeType::Identifier === $attribute->getType()) {
                $identifierAttributes[$code] = $attribute;
            }
        }
        if ([] === $identifierAttributes) {
            return;
        }

        /** @var array<string, array<string, true>> $valuesByAttributeId */
        $valuesByAttributeId = [];
        foreach ($prepared as $row) {
            if (!$row['rowOk']) {
                continue;
            }
            foreach ($row['resolvedValues'] as $resolved) {
                $attribute = $identifierAttributes[$resolved->attributeCode] ?? null;
                $value = $resolved->rawValue;
                if (null === $attribute || null === $value || '' === $value) {
                    continue;
                }
                $valuesByAttributeId[$attribute->getId()->toRfc4122()][$value] = true;
            }
        }

        $existingOwners = $this->fetchIdentifierOwners(
            $tenant,
            $this->requireTargetObjectType($session)->getId()->toRfc4122(),
            $valuesByAttributeId,
        );

        foreach ($prepared as $index => $row) {
            if (!$row['rowOk']) {
                continue;
            }
            $ownObjectId = ($existingByKey[$row['matchKey']] ?? null)?->getId()->toRfc4122();
            foreach ($row['resolvedValues'] as $resolved) {
                $attribute = $identifierAttributes[$resolved->attributeCode] ?? null;
                $value = $resolved->rawValue;
                if (null === $attribute || null === $value || '' === $value) {
                    continue;
                }
                $attrCode = $resolved->attributeCode;
                $attrId = $attribute->getId()->toRfc4122();

                $owner = $existingOwners[$attrId][$value] ?? null;
                if (null !== $owner && $owner !== $ownObjectId) {
                    $prepared[$index]['rowOk'] = false;
                    $prepared[$index]['errors'][] = new ValidationError(
                        rowNumber: $row['rowNumber'],
                        sku: $row['sku'],
                        errorType: ImportErrorType::InvalidValue,
                        level: ImportLogLevel::Error,
                        message: \sprintf('Identifier "%s" = "%s" is already used by another object.', $attrCode, $value),
                        columnName: $attrCode,
                        columnValue: $value,
                    );

                    continue 2;
                }

                if (isset($identifierSeenInFile[$attrCode][$value])) {
                    $prepared[$index]['rowOk'] = false;
                    $prepared[$index]['duplicateInFile'] = true;
                    $prepared[$index]['errors'][] = new ValidationError(
                        rowNumber: $row['rowNumber'],
                        sku: $row['sku'],
                        errorType: ImportErrorType::DuplicateSkuInFile,
                        level: ImportLogLevel::Warning,
                        message: \sprintf('Identifier "%s" = "%s" already appeared in the file at row %d — skipped.', $attrCode, $value, $identifierSeenInFile[$attrCode][$value]),
                        columnName: $attrCode,
                        columnValue: $value,
                    );

                    continue 2;
                }

                $identifierSeenInFile[$attrCode][$value] = $row['rowNumber'];
            }
        }
    }

    /**
     * One bulk lookup of identifier owners for the chunk's candidate values.
     *
     * @param array<string, array<string, true>> $valuesByAttributeId
     *
     * @return array<string, array<string, string>> attributeId → (value → object_id)
     */
    private function fetchIdentifierOwners(Tenant $tenant, string $objectTypeId, array $valuesByAttributeId): array
    {
        if ([] === $valuesByAttributeId) {
            return [];
        }
        $allValues = [];
        foreach ($valuesByAttributeId as $values) {
            foreach (array_keys($values) as $value) {
                $allValues[$value] = true;
            }
        }

        // Query the authoritative JSONB value (`value->>'value'`) joined to the
        // target ObjectType rather than the trigger-maintained denormalised
        // identifier_* columns: the JSONB is always populated (the denorm
        // columns + partial-unique index are a production-only migration, absent
        // in the ORM-metadata test schema), so the pre-check is correct in both.
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT ov.attribute_id AS attribute_id, ov.value->>\'value\' AS ident, ov.object_id AS object_id'
            .' FROM object_values ov JOIN objects o ON o.id = ov.object_id'
            .' WHERE ov.tenant_id = :tenant AND o.object_type_id = :ot'
            .' AND ov.attribute_id IN (:attrs) AND ov.value->>\'value\' IN (:vals)',
            [
                'tenant' => $tenant->getId()->toRfc4122(),
                'ot' => $objectTypeId,
                'attrs' => array_keys($valuesByAttributeId),
                'vals' => array_keys($allValues),
            ],
            [
                'attrs' => ArrayParameterType::STRING,
                'vals' => ArrayParameterType::STRING,
            ],
        );

        $map = [];
        foreach ($rows as $row) {
            $attributeId = $row['attribute_id'];
            $ident = $row['ident'];
            $objectId = $row['object_id'];
            if (!\is_scalar($attributeId) || !\is_scalar($ident) || !\is_scalar($objectId)) {
                continue;
            }
            $map[(string) $attributeId][(string) $ident] = (string) $objectId;
        }

        return $map;
    }

    /**
     * IMP2-1.9 (items 3–4) — record the session outcome after a throw that may
     * have CLOSED the EntityManager (anything from flush()). Resets the manager
     * via the registry so the write lands on a fresh, open one. A
     * connection-level fault is systemic → `failed`; anything else that slipped
     * the pre-flush checks degrades to `partial`, preserving the rows committed
     * by earlier chunks. Full per-row replay of the failed chunk is deferred to
     * IMP2-2.3 (it shares the EM-lifecycle rework that pause/resume needs).
     */
    private function recordOutcomeAfterException(Uuid $sessionId, Throwable $exception): void
    {
        try {
            $this->managerRegistry->resetManager();
        } catch (Throwable) {
            // Best effort — still try to fetch a usable manager below.
        }

        $em = $this->managerRegistry->getManager();
        if (!$em instanceof EntityManagerInterface) {
            return;
        }
        $session = $em->find(ImportSession::class, $sessionId);
        // IMP2-2.3 — Paused is NOT terminal but the operator's intent must win:
        // a pause that landed just before a late exception must not be
        // overwritten with failed/partial. (Cancelled is already terminal.)
        if (!$session instanceof ImportSession
            || $session->getStatus()->isTerminal()
            || ImportSessionStatus::Paused === $session->getStatus()) {
            return;
        }

        if ($this->isSystemicDbFailure($exception)) {
            $session->markFailed('Import aborted by a system fault: '.$exception->getMessage());
        } else {
            // A data fault slipped the pre-flush checks (rare). Keep the rows
            // committed by earlier chunks and finalize as partial rather than
            // discarding the whole run.
            $session->incrementError();
            $session->markCompleted();
            // #2815 — say WHY. This path used to finalize a half-imported
            // catalogue as `partial` with an empty error_message, so the only
            // account of the abort was in the container log: the operator saw
            // a truncated import and no stated reason for it.
            $session->recordErrorMessage(\sprintf(
                'Import przerwany po %d wierszach: %s',
                $session->getProcessedRows(),
                $exception->getMessage(),
            ));
        }
        $em->flush();

        try {
            $this->progressPublisher->completed($session);
        } catch (Throwable) {
            // Progress channel is best-effort; the session row is the contract.
        }
    }

    private function isSystemicDbFailure(Throwable $exception): bool
    {
        // ConnectionLost extends ConnectionException, so this covers a dropped
        // connection mid-flush too — the systemic fault that warrants `failed`.
        for ($cursor = $exception; null !== $cursor; $cursor = $cursor->getPrevious()) {
            if ($cursor instanceof ConnectionException) {
                return true;
            }
        }

        return false;
    }

    /**
     * IMP2-1.8 — buffer a parent link for pass 2 when the row carries a
     * non-empty `__parent_sku__` cell. Existence / cycle validation happens
     * in pass 2 once every object is written.
     *
     * @param array<string, string|null> $cells
     * @param array<string, string>      $columnMapping
     */
    private function recordParentLink(string $childSku, array $cells, array $columnMapping, int $rowNumber): void
    {
        $parentSku = $this->rowCells->reservedCell($cells, $columnMapping, ReservedMappingTarget::PARENT_SKU);
        if (null !== $parentSku) {
            $this->relationStep->recordParent($childSku, $parentSku, $rowNumber);
        }
    }

    /**
     * IMP2-1.8 — buffer relation links from Relation/Reference cells for
     * pass 2. The values were NOT written as ObjectValue (the creator skips
     * them); their pipe-separated target codes become object_relations rows.
     *
     * @param list<ResolvedImportValue> $resolvedValues
     * @param array<string, Attribute>  $attributesByCode
     */
    private function recordRelationLinks(string $sourceSku, array $resolvedValues, array $attributesByCode, int $rowNumber): void
    {
        foreach ($resolvedValues as $resolved) {
            $attribute = $attributesByCode[$resolved->attributeCode] ?? null;
            if (!$attribute instanceof Attribute) {
                continue;
            }
            if (AttributeType::Relation !== $attribute->getType() && AttributeType::Reference !== $attribute->getType()) {
                continue;
            }
            $raw = $resolved->rawValue;
            if (null === $raw || '' === $raw) {
                continue;
            }
            $targetCodes = array_values(array_filter(
                array_map('trim', explode('|', $raw)),
                static fn (string $code): bool => '' !== $code,
            ));
            if ([] !== $targetCodes) {
                $this->relationStep->recordRelation($sourceSku, $attribute->getCode(), $targetCodes, $rowNumber);
            }
        }
    }

    /**
     * IMP2-1.12 — buffer an image-download job for each Asset-attribute cell on
     * the row that carries http(s) URLs. Existing-asset UUIDs in the same cell
     * ride along so the handler writes ONE merged `{asset_id}` envelope. The
     * object id is captured as a string so the buffered job survives
     * flushAndClear.
     *
     * @param list<ImageDownloadJob>                                                                                                                                                                                 $mediaJobs        by ref
     * @param array{rowNumber: int, cells: array<string, string|null>, sku: ?string, errors: list<ValidationError>, rowOk: bool, resolvedValues: list<ResolvedImportValue>, matchKey: string, duplicateInFile: bool} $row
     * @param array<string, Attribute>                                                                                                                                                                               $attributesByCode
     */
    private function collectMediaJobs(array &$mediaJobs, ImportSession $session, Uuid $objectId, array $row, array $attributesByCode): void
    {
        foreach ($row['resolvedValues'] as $resolved) {
            $attribute = $attributesByCode[$resolved->attributeCode] ?? null;
            if (!$attribute instanceof Attribute || AttributeType::Asset !== $attribute->getType()) {
                continue;
            }
            $raw = $resolved->rawValue;
            if (null === $raw || '' === $raw) {
                continue;
            }
            $classified = $this->assetUrlResolver->classify($raw);
            // IMP2-1.13 — in ZIP mode a bare-filename token is a ZIP entry to
            // extract (not unresolved); in HTTP/none mode it stays unresolved
            // and is warned (1c) — relative paths arrive in IMP2-3.4.
            $zipMode = ImportImageSource::Zip === $session->getImageSource();
            $zipNames = $zipMode ? $classified['unresolved'] : [];
            if (!$zipMode) {
                foreach ($classified['unresolved'] as $token) {
                    if (!$this->importLogBudgetAvailable()) {
                        continue;
                    }
                    $this->entityManager->persist(new ImportLog(
                        importSession: $session,
                        rowNumber: $row['rowNumber'],
                        level: ImportLogLevel::Warning,
                        message: \sprintf('Asset reference "%s" is neither a known asset id nor an http(s) URL — skipped.', $token),
                        sku: $row['sku'],
                        errorType: ImportErrorType::ImageNotFound->value,
                        columnName: $resolved->attributeCode,
                        columnValue: $token,
                    ));
                }
            }
            if ([] === $classified['urls'] && [] === $zipNames) {
                continue; // pure UUID / handled by the value writer
            }
            $mediaJobs[] = new ImageDownloadJob(
                objectId: $objectId->toRfc4122(),
                attributeCode: $resolved->attributeCode,
                locale: $resolved->locale,
                channelId: $resolved->channelId?->toRfc4122(),
                existingUuids: $classified['uuids'],
                urls: $classified['urls'],
                rowNumber: $row['rowNumber'],
                sku: $row['sku'],
                zipNames: $zipNames,
            );
        }
    }

    /**
     * IMP2-1.12 — dispatch buffered media jobs in batches to the `import`
     * transport, bumping the session's pending-batch counter per batch so the
     * run is not finalized until every batch reports back. Returns a fresh
     * managed session (a sync-transport handler may have cleared the EM).
     *
     * @param list<ImageDownloadJob> $mediaJobs
     */
    private function dispatchMediaBatches(ImportSession $session, array $mediaJobs): ImportSession
    {
        if ([] === $mediaJobs) {
            return $session;
        }
        foreach (array_chunk($mediaJobs, self::MEDIA_BATCH_SIZE) as $batch) {
            $session = $this->refreshSession($session);
            $tenant = $session->getTenant();
            if (!$tenant instanceof Tenant) {
                break;
            }
            // IMP2-1.13 — pass the run's ZIP location so the handler stages +
            // extracts entries (only in zip mode with an uploaded archive).
            $zipStoragePath = null;
            if (ImportImageSource::Zip === $session->getImageSource() && null !== $session->getZipFileName()) {
                $zipStoragePath = \sprintf('%s/%s/%s', $tenant->getId()->toRfc4122(), $session->getId()->toRfc4122(), $session->getZipFileName());
            }
            $session->incrementPendingImageBatches();
            $this->sessions->save($session);
            // TenantStamp so the async worker rebinds the tenant before the
            // handler runs (TenantContextRebindingMiddleware) — mirrors the
            // ImportRunMessage dispatch; without it the worker dead-letters.
            $this->messageBus->dispatch(
                // #2731 — per-batch idempotency key: the handler records it on
                // the session so a redelivery cannot double-count images.
                new ImageDownloadMessage($session->getId(), $tenant->getId(), $batch, $zipStoragePath, Uuid::v7()),
                [new TenantStamp($tenant->getId())],
            );
        }

        return $this->refreshSession($session);
    }

    /**
     * Pulls the uploaded source file from the imports bucket into a
     * local temp path so PhpSpreadsheet / league-csv can read it
     * through a regular filesystem handle. The temp path is unlinked
     * in the finally block of the caller.
     *
     * Convention (spec §8.6): `{tenant_id}/{session_id}/{file_name}`.
     */
    /**
     * The CatalogObject pipeline always runs against a concrete ObjectType.
     * Structural imports (attributes / attribute groups) carry no target and
     * never reach this handler, so a null here is a programming error.
     */
    /**
     * DP-07 (#2037, ADR-0025) — extend the primed attribute set with codes
     * referenced by the target ObjectType's cross-field rules, so
     * BatchValueWriter::primeChunk() loads existing counterpart values even
     * when the rule's other side is absent from the import mapping. A code
     * that stays unmapped only UNDER-enforces (compare skips, condition
     * false) — never a false positive.
     *
     * @param array<string, Attribute> $attributesByCode
     *
     * @return array<string, Attribute>
     */
    private function withCrossFieldRuleAttributes(
        array $attributesByCode,
        ImportSession $session,
        Tenant $tenant,
    ): array {
        $target = $session->getTargetObjectType();
        if (!$target instanceof \App\Catalog\Domain\Entity\ObjectType) {
            return $attributesByCode;
        }

        foreach ($this->crossFieldRules->rulesFor($target) as $rule) {
            foreach ($rule->referencedCodes() as $code) {
                if (isset($attributesByCode[$code])) {
                    continue;
                }
                $attribute = $this->attributeRepository->findByCode($code, $tenant);
                if ($attribute instanceof Attribute) {
                    $attributesByCode[$code] = $attribute;
                }
            }
        }

        return $attributesByCode;
    }

    private function requireTargetObjectType(ImportSession $session): \App\Catalog\Domain\Entity\ObjectType
    {
        $target = $session->getTargetObjectType();
        if (!$target instanceof \App\Catalog\Domain\Entity\ObjectType) {
            throw new RuntimeException(\sprintf(
                'Catalog import session %s has no target ObjectType.',
                $session->getId()->toRfc4122(),
            ));
        }

        return $target;
    }

    private function stageSourceFile(ImportSession $session, Tenant $tenant): string
    {
        $remotePath = \sprintf(
            '%s/%s/%s',
            $tenant->getId()->toRfc4122(),
            $session->getId()->toRfc4122(),
            $session->getFileName(),
        );

        try {
            $stream = $this->importsStorage->readStream($remotePath);
        } catch (FilesystemException $exception) {
            throw new RuntimeException(\sprintf(
                'Failed to read uploaded file at "%s".',
                $remotePath,
            ), previous: $exception);
        }

        $extension = pathinfo($session->getFileName(), PATHINFO_EXTENSION);
        if ('' === $extension) {
            $extension = 'csv';
        }
        $localPath = tempnam(sys_get_temp_dir(), 'pim-import-').'.'.$extension;
        $local = fopen($localPath, 'w');
        if (false === $local) {
            throw new RuntimeException(\sprintf('Failed to open temp file "%s" for writing.', $localPath));
        }
        stream_copy_to_stream($stream, $local);
        fclose($local);

        return $localPath;
    }

    /**
     * After {@see flushAndClear()} the EM detached the active session,
     * so any further state mutation has to happen on a fresh managed
     * instance. Repository lookup re-merges and keeps counters live.
     */
    /**
     * IMP2-2.3 — true once the operator pressed Pauza / Anuluj: the per-chunk
     * refresh reloaded the status the endpoint persisted. The handler then
     * stops gracefully (checkpoint already saved) and the lock releases in the
     * finally block, leaving the session paused/cancelled rather than failed.
     */
    private function haltRequested(ImportSession $session): bool
    {
        $status = $session->getStatus();

        return ImportSessionStatus::Paused === $status || ImportSessionStatus::Cancelled === $status;
    }

    /**
     * IMP2-2.3 — read the COMMITTED status straight from the DB (not the
     * possibly-stale in-memory entity). Used at finalization, where
     * dispatchMediaBatches() may return a session that was never re-merged, so
     * a pause/cancel persisted by the endpoint during media dispatch would be
     * invisible to {@see haltRequested()} and the session wrongly completed.
     */
    private function persistedStatusHalts(ImportSession $session): bool
    {
        $status = $this->entityManager->getConnection()->fetchOne(
            'SELECT status FROM import_sessions WHERE id = :id',
            ['id' => $session->getId()->toRfc4122()],
        );

        return ImportSessionStatus::Paused->value === $status
            || ImportSessionStatus::Cancelled->value === $status;
    }

    private function refreshSession(ImportSession $session): ImportSession
    {
        $reloaded = $this->sessions->findById($session->getId());
        if (!$reloaded instanceof ImportSession) {
            throw new RuntimeException(\sprintf(
                'Import session "%s" disappeared mid-run.',
                $session->getId()->toRfc4122(),
            ));
        }

        return $reloaded;
    }

    /**
     * IMP2-1.3 — the row match key: the cell mapped to the configured
     * identifier attribute, or the SKU cell by default (trimmed; the
     * synthetic IMPORT-{row} fallback only feeds brand-new rows).
     *
     * @param array<string, string> $columnMapping
     */
    /**
     * IMP2-1.4 (#1466) — validate + resolve + write one buffered chunk.
     * Returns the number of rows consumed (== chunk size).
     *
     * @param list<array{rowNumber: int, cells: array<string, string|null>}> $buffer
     * @param array<string, string>                                          $columnMapping
     * @param array<string, Attribute>                                       $attributesByCode
     * @param array<string, int>                                             $skuSeenInFile
     * @param array<string, array<string, int>>                              $identifierSeenInFile attributeCode → (value → rowNumber)
     * @param list<ImageDownloadJob>                                         $mediaJobs            accumulated across chunks (IMP2-1.12), by ref
     * @param int                                                            $resumeFrom           IMP2-2.3 — rows ≤ this were written by a prior run: skip their writes, re-buffer links only
     */
    private function processChunk(
        ImportSession $session,
        Tenant $tenant,
        array $buffer,
        array $columnMapping,
        array $attributesByCode,
        array &$skuSeenInFile,
        array &$identifierSeenInFile,
        array &$mediaJobs,
        int $resumeFrom = 0,
    ): int {
        $target = $session->getTargetObjectType();
        $identityAttributeCode = null !== $target
            ? $this->validator->identityAttributeCode($target, $session->getMatchAttributeCode())
            : $session->getMatchAttributeCode();

        // Pass 1 — validate rows and gather match keys for the batch resolve.
        /** @var list<array{rowNumber: int, cells: array<string, string|null>, sku: ?string, errors: list<ValidationError>, rowOk: bool, resolvedValues: list<ResolvedImportValue>, matchKey: string, duplicateInFile: bool}> $prepared */
        $prepared = [];
        $matchKeys = [];
        foreach ($buffer as $entry) {
            $rowNumber = $entry['rowNumber'];
            $cells = $entry['cells'];
            $errors = $this->validator->validateRow(
                rowNumber: $rowNumber,
                cells: $cells,
                columnMapping: $columnMapping,
                attributesByCode: $attributesByCode,
                tenant: $tenant,
                skuSeenInFile: $skuSeenInFile,
                // #2943 — same identity rule the dry run applied, resolved from
                // the run's own match attribute. Deriving it here rather than
                // defaulting to `sku` is what keeps the async pass from
                // rejecting rows the preview accepted.
                identityAttributeCode: $identityAttributeCode,
            );
            $sku = $cells[$this->rowCells->skuColumnHeader($columnMapping)] ?? null;
            $blocking = array_values(array_filter(
                $errors,
                static fn (ValidationError $error): bool => $error->isRowBlocking(),
            ));
            // IMP2-1.9 (D1) — a Warning-level DuplicateSkuInFile does not block
            // (severity contract) but the duplicate row must be SKIPPED, not
            // imported. Kept distinct from a hard Error, which is a row error.
            $duplicateInFile = [] !== array_filter(
                $errors,
                static fn (ValidationError $error): bool => ImportErrorType::DuplicateSkuInFile === $error->errorType,
            );
            $rowOk = [] === $blocking && !$duplicateInFile;
            $resolvedValues = [];
            if ($rowOk) {
                // IMP2-1.9 (item 5) — value materialization can throw on a
                // pathological/junk row (e.g. a section header in the middle of
                // the data). Degrade to a row error instead of aborting the run.
                try {
                    $resolvedValues = $this->materialiseValues($cells, $columnMapping, $tenant);
                } catch (Throwable $exception) {
                    $rowOk = false;
                    $errors[] = new ValidationError(
                        rowNumber: $rowNumber,
                        sku: $sku,
                        errorType: ImportErrorType::InvalidValue,
                        level: ImportLogLevel::Error,
                        message: 'Row could not be parsed: '.$exception->getMessage(),
                        columnValue: $this->rowCells->rawRowSnippet($cells),
                    );
                }
            }
            $matchKey = $rowOk ? $this->matchKey($session, $cells, $columnMapping, $resolvedValues, $rowNumber) : '';
            if ('' !== $matchKey) {
                $matchKeys[] = $matchKey;
            }
            $prepared[] = [
                'rowNumber' => $rowNumber,
                'cells' => $cells,
                'sku' => $sku,
                'errors' => $errors,
                'rowOk' => $rowOk,
                'resolvedValues' => $resolvedValues,
                'matchKey' => $matchKey,
                'duplicateInFile' => $duplicateInFile,
            ];
        }

        $existingByKey = $this->objectResolver->resolveMany(
            $matchKeys,
            $this->requireTargetObjectType($session),
            $tenant,
            $session->getMatchAttributeCode(),
        );
        $this->valueWriter->primeChunk(array_values($existingByKey), $attributesByCode);
        // IMP2-2.4 — snapshot the current values of the chunk's UPDATE targets
        // (one query) so the undo-log can record before-state per overwritten cell.
        $this->undoLogger->primeChunk(array_values($existingByKey));

        // IMP2-1.7: resolve every distinct category code in the chunk once
        // (per-chunk prefetch — not one SELECT per code per row).
        $categoryByCode = $this->resolveChunkCategories($prepared, $columnMapping, $tenant);
        // IMP2-1.8 galleries: prefetch which referenced asset ids actually
        // exist (tenant-scoped) so the creator can drop dangling ids with a
        // row warning — one query per chunk, mirroring the category prefetch.
        $existingAssetIds = $this->resolveChunkAssets($prepared, $attributesByCode, $tenant);
        // IMP2-1.9 (item 2): set-based identifier collision pre-check BEFORE the
        // flush — a value already used in the catalog (vs-DB) blocks the row as
        // an Error; a value duplicated earlier in the file is a skip (D1). This
        // catches the DB partial-unique-index violation gracefully instead of
        // letting flush() explode and close the EM.
        $this->precheckIdentifiers($prepared, $attributesByCode, $existingByKey, $session, $tenant, $identifierSeenInFile);
        /** @var list<array{product: CatalogObject, codes: list<string>, append: bool}> $pendingCategoryOps */
        $pendingCategoryOps = [];

        // Pass 2 — decide + write per row.
        foreach ($prepared as $row) {
            // IMP2-2.3 — this row was already written by a prior (paused/crashed)
            // run: leave the persisted counters + logs untouched and skip the
            // write, but re-buffer its cross-object links so pass 2 still wires
            // variants/relations whose master may live in a not-yet-skipped row.
            if ($resumeFrom > 0 && $row['rowNumber'] <= $resumeFrom) {
                if ($row['rowOk']) {
                    // Use the object's REAL code: a row matched by a non-code
                    // identifier (matchAttributeCode) updates an object whose
                    // code differs from the SKU cell, so links must key on the
                    // existing code (mirrors the Update branch) — falling back
                    // to the SKU only for a fresh create.
                    $existing = $existingByKey[$row['matchKey']] ?? null;
                    $code = null !== $existing
                        ? $existing->getCode()
                        : $this->rowCells->skuFrom($row['resolvedValues'], $row['rowNumber']);
                    $this->recordParentLink($code, $row['cells'], $columnMapping, $row['rowNumber']);
                    $this->recordRelationLinks($code, $row['resolvedValues'], $attributesByCode, $row['rowNumber']);
                    // The prior run already logged this row's before-state; keep
                    // first-write-wins honest so a later row in THIS run touching
                    // the same scope cannot append a duplicate undo entry.
                    if (null !== $existing) {
                        $this->undoLogger->markScopesCaptured($existing, $row['resolvedValues'], $attributesByCode, $tenant);
                        // IMP2-2.6 — this row was written by the prior (paused)
                        // run under BulkContext, but that run paused before the
                        // rebuild dispatch fired. On resume we skip the re-write,
                        // yet must still re-collect the object so the end-of-run
                        // dispatch rebuilds its attributes_indexed + reindexes it
                        // — otherwise a paused→resumed import leaves the first
                        // run's objects unindexed (re-collecting an unchanged
                        // object is an idempotent no-op rebuild).
                        $this->bulkTouchedIds[] = $existing->getId()->toRfc4122();
                    }
                }

                continue;
            }

            $errors = $row['errors'];
            $issues = [];

            if ($row['rowOk']) {
                $existing = $existingByKey[$row['matchKey']] ?? null;
                $decision = $this->objectResolver->decide($session->getMode(), $existing);

                if (ImportRowDecision::Create === $decision) {
                    $created = $this->creator->create(
                        objectType: $this->requireTargetObjectType($session),
                        sku: $this->rowCells->skuFrom($row['resolvedValues'], $row['rowNumber']),
                        resolvedValues: $row['resolvedValues'],
                        attributesByCode: $attributesByCode,
                        importSessionId: $session->getId(),
                        categories: $this->categoryOps->resolveCategories($this->rowCells->extractCategoryCodes($row['cells'], $columnMapping), $categoryByCode),
                        status: $this->rowCells->extractStatus($row['cells'], $columnMapping),
                        enabled: $this->rowCells->extractEnabled($row['cells'], $columnMapping),
                        variantAxes: $this->rowCells->extractVariantAxes($row['cells'], $columnMapping),
                        existingAssetIds: $existingAssetIds,
                        createMissingOptions: $session->createMissingOptions(),
                    );
                    $issues = $created->issues;
                    $session->incrementSuccess();
                    // IMP2-2.6 — a created object needs its attributes_indexed
                    // built + Meili doc indexed; the sync path is off under
                    // BulkContext, so collect it for the end-of-run async rebuild.
                    $this->bulkTouchedIds[] = $created->object->getId()->toRfc4122();
                    // IMP2-1.8: buffer parent + relation links; resolved in
                    // pass 2 once every object exists (target row may precede).
                    $createdSku = $this->rowCells->skuFrom($row['resolvedValues'], $row['rowNumber']);
                    $this->recordParentLink($createdSku, $row['cells'], $columnMapping, $row['rowNumber']);
                    $this->recordRelationLinks($createdSku, $row['resolvedValues'], $attributesByCode, $row['rowNumber']);
                    $this->collectMediaJobs($mediaJobs, $session, $created->object->getId(), $row, $attributesByCode);
                } elseif (ImportRowDecision::Update === $decision && null !== $existing) {
                    // IMP2-2.4 — capture the before-state of every value this row
                    // is about to overwrite/add on the pre-existing object, so
                    // rollback v2 can restore it.
                    $this->undoLogger->captureValueWrites($session, $existing, $row['resolvedValues'], $attributesByCode, $tenant);
                    $updateResult = $this->creator->update(
                        $existing,
                        $row['resolvedValues'],
                        $attributesByCode,
                        $this->rowCells->extractStatus($row['cells'], $columnMapping),
                        $this->rowCells->extractEnabled($row['cells'], $columnMapping),
                        $this->rowCells->extractVariantAxes($row['cells'], $columnMapping),
                        $existingAssetIds,
                        $session->createMissingOptions(),
                    );
                    $issues = $updateResult['issues'];
                    // IMP2-2.6 — a row whose values all already matched is a no-op
                    // re-import (zero object_values UPDATE): count it as `skipped`,
                    // not `updated`, so a re-imported unchanged export reports 100%
                    // skipped. Any actual value change makes it a real update.
                    if ($updateResult['changed'] > 0) {
                        $session->incrementSuccess();
                        $session->incrementUpdated();
                        // IMP2-2.6 — values changed → async rebuild + reindex.
                        $this->bulkTouchedIds[] = $existing->getId()->toRfc4122();
                    } else {
                        $session->incrementSkipped();
                    }
                    $this->recordParentLink($existing->getCode(), $row['cells'], $columnMapping, $row['rowNumber']);
                    $this->recordRelationLinks($existing->getCode(), $row['resolvedValues'], $attributesByCode, $row['rowNumber']);
                    $this->collectMediaJobs($mediaJobs, $session, $existing->getId(), $row, $attributesByCode);
                    // IMP2-1.7: category replace/append runs after the value
                    // pass (replaceForProduct flushes around the primary index).
                    // Empty cell = untouched (D2), so only collect non-empty.
                    $categoryCodes = $this->rowCells->extractCategoryCodes($row['cells'], $columnMapping);
                    if ([] !== $categoryCodes) {
                        $pendingCategoryOps[] = [
                            'product' => $existing,
                            'codes' => $categoryCodes,
                            'append' => $this->rowCells->categoryAppend($columnMapping),
                        ];
                    }
                } else {
                    $session->incrementSkipped();
                    $errors[] = ImportRowDecision::SkipExists === $decision
                        ? new ValidationError(
                            rowNumber: $row['rowNumber'],
                            sku: $row['sku'],
                            errorType: ImportErrorType::DuplicateSkuInDb,
                            level: ImportLogLevel::Warning,
                            message: \sprintf('Match key "%s" already exists — row skipped (mode CREATE).', $row['matchKey']),
                        )
                        : new ValidationError(
                            rowNumber: $row['rowNumber'],
                            sku: $row['sku'],
                            errorType: ImportErrorType::NoMatchInDb,
                            level: ImportLogLevel::Warning,
                            message: \sprintf('No object matches key "%s" — row skipped (mode UPDATE).', $row['matchKey']),
                        );
                }
            } elseif ($row['duplicateInFile']) {
                // IMP2-1.9 (D1) — duplicate of an earlier file row: skip, do not
                // count as an error (the Warning is still logged below).
                $session->incrementSkipped();
            } else {
                $session->incrementError();
            }

            // Writer issues are value-level: the row stays imported, the
            // offending value is skipped — surfaced as warnings.
            foreach ($issues as $issue) {
                $errors[] = new ValidationError(
                    rowNumber: $row['rowNumber'],
                    sku: $row['sku'],
                    errorType: 'required_empty' === $issue['kind'] ? ImportErrorType::MissingRequired : ImportErrorType::InvalidValue,
                    level: ImportLogLevel::Warning,
                    message: $issue['message'],
                    columnName: $issue['attributeCode'],
                );
            }

            foreach ($errors as $error) {
                if (!$this->importLogBudgetAvailable()) {
                    continue;
                }
                $this->entityManager->persist(new ImportLog(
                    importSession: $session,
                    rowNumber: $error->rowNumber,
                    level: $error->level,
                    message: $error->message,
                    sku: $error->sku,
                    errorType: $error->errorType->value,
                    columnName: $error->columnName,
                    columnValue: $error->columnValue,
                ));
                // IMP2-2.6 — only ROW-BLOCKING errors go per-row on Mercure for the
                // live log; blocking errors are rare. Warnings (e.g. per-row
                // duplicate findings on a self-reimport) stay in the persisted
                // ImportLog only — emitting them would re-introduce O(N) hub POSTs.
                // The per-row `row_processed` ticker is gone (replaced by the
                // throttled `progress` snapshots in run()).
                if ($error->isRowBlocking()) {
                    $this->progressPublisher->error($session, $error->rowNumber, $error->sku, $error->errorType->value, $error->message);
                }
            }

            // IMP2-2.6 — feed the throttled `progress` snapshot's current_sku
            // instead of emitting a Mercure event per row.
            $this->lastProcessedSku = $row['sku'];
        }

        // IMP2-1.7: category replace/append for UPDATE rows, after the value
        // writes are staged (replaceForProduct flushes the EM in its own
        // transaction to DELETE-then-INSERT around the primary unique index).
        $this->categoryOps->applyCategoryOps($pendingCategoryOps, $categoryByCode);

        return \count($prepared);
    }

    /**
     * Resolve every distinct category code referenced in the chunk to its
     * {@see CatalogObject} once. Unresolved codes are dropped (the validator
     * emitted the per-code CategoryNotFound warning).
     *
     * @param list<array{rowNumber: int, cells: array<string, string|null>, sku: ?string, errors: list<ValidationError>, rowOk: bool, resolvedValues: list<ResolvedImportValue>, matchKey: string, duplicateInFile: bool}> $prepared
     * @param array<string, string>                                                                                                                                                                                        $columnMapping
     *
     * @return array<string, CatalogObject>
     */
    private function resolveChunkCategories(array $prepared, array $columnMapping, Tenant $tenant): array
    {
        $codes = [];
        foreach ($prepared as $row) {
            if (!$row['rowOk']) {
                continue;
            }
            foreach ($this->rowCells->extractCategoryCodes($row['cells'], $columnMapping) as $code) {
                // Key to dedupe, but keep the string code as the VALUE: PHP
                // coerces a numeric-string array key to int (e.g. an IdoSell
                // category id "1214553885"), and an int reaching findByCode's
                // string signature throws a TypeError that crashes the whole run.
                $codes[$code] = $code;
            }
        }

        $map = [];
        foreach ($codes as $code) {
            $category = $this->catalogObjects->findByCode($code, ObjectKind::Category, $tenant);
            if (null !== $category) {
                $map[$code] = $category;
            }
        }

        return $map;
    }

    /**
     * IMP2-1.8 galleries — collect every asset id referenced by an Asset-type
     * cell in the chunk (pipe-split) and return the subset that exists for the
     * tenant as a lower-cased lookup set. One query per chunk; the creator uses
     * the set to drop dangling ids with a row warning.
     *
     * @param list<array{rowNumber: int, cells: array<string, string|null>, sku: ?string, errors: list<ValidationError>, rowOk: bool, resolvedValues: list<ResolvedImportValue>, matchKey: string, duplicateInFile: bool}> $prepared
     * @param array<string, Attribute>                                                                                                                                                                                     $attributesByCode
     *
     * @return array<string, true>
     */
    private function resolveChunkAssets(array $prepared, array $attributesByCode, Tenant $tenant): array
    {
        $ids = [];
        foreach ($prepared as $row) {
            if (!$row['rowOk']) {
                continue;
            }
            foreach ($row['resolvedValues'] as $resolved) {
                $attribute = $attributesByCode[$resolved->attributeCode] ?? null;
                if (!$attribute instanceof Attribute || AttributeType::Asset !== $attribute->getType()) {
                    continue;
                }
                $raw = $resolved->rawValue;
                if (null === $raw || '' === $raw) {
                    continue;
                }
                // IMP2-1.12 — only UUID tokens are existing-asset references;
                // URL tokens are downloaded by the media path and bare strings
                // are unresolved. Querying a non-UUID against the uuid id column
                // would blow up the prefetch.
                foreach ($this->assetUrlResolver->classify($raw)['uuids'] as $id) {
                    $ids[$id] = true;
                }
            }
        }

        if ([] === $ids) {
            return [];
        }

        $set = [];
        foreach ($this->assets->existingIds(array_keys($ids), $tenant) as $existing) {
            $set[strtolower($existing)] = true;
        }

        return $set;
    }

    /**
     * IMP2-1.3 — the row match key: the cell mapped to the configured
     * identifier attribute, or the SKU cell by default (trimmed).
     *
     * @param array<string, string|null> $cells
     * @param array<string, string>      $columnMapping
     * @param list<ResolvedImportValue>  $resolvedValues
     */
    private function matchKey(
        ImportSession $session,
        array $cells,
        array $columnMapping,
        array $resolvedValues,
        int $rowNumber,
    ): string {
        $matchCode = $session->getMatchAttributeCode();
        if (null !== $matchCode) {
            foreach ($columnMapping as $header => $target) {
                if ($target === $matchCode) {
                    return trim($cells[$header] ?? '');
                }
            }

            return '';
        }

        return trim($this->rowCells->skuFrom($resolvedValues, $rowNumber));
    }
}
