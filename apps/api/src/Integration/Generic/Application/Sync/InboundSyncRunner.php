<?php

declare(strict_types=1);

namespace App\Integration\Generic\Application\Sync;

use App\Catalog\Contracts\Integration\InboundRecordWriter;
use App\Catalog\Contracts\Integration\InboundUpsertResult;
use App\Integration\Generic\Domain\Entity\FieldMapping;
use App\Integration\Generic\Domain\Entity\RemoteEndpoint;
use App\Integration\Generic\Domain\Entity\SyncBinding;
use App\Integration\Generic\Domain\Entity\SyncRun;
use App\Integration\Generic\Domain\Entity\SyncRunLog;
use App\Integration\Generic\Domain\Enum\SyncDirection;
use App\Integration\Generic\Domain\Enum\SyncRecordAction;
use App\Integration\Generic\Domain\Enum\SyncRunStatus;
use App\Integration\Generic\Domain\Repository\FieldMappingRepositoryInterface;
use App\Integration\Generic\Domain\Repository\SyncRunRepositoryInterface;
use App\Integration\Generic\Infrastructure\Http\Pagination\PaginatedFetcher;
use App\Integration\Generic\Infrastructure\Http\RecordSelector;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Runs one inbound (remote → PIM) sync of a {@see SyncBinding} (APIC-P3-04).
 *
 * Walks the read endpoint page by page ({@see PaginatedFetcher}), maps each
 * record through the connection's inbound field mappings ({@see RecordMapper})
 * and upserts it via the cross-BC write seam ({@see InboundRecordWriter},
 * Provenance::Integration). Each record is flushed before the next so the
 * resolve-by-match-key sees committed state (idempotent — no in-page dup
 * creates); the cursor advances once per page ({@see CursorManager}, monotonic
 * + crash-safe). Every record is logged ({@see SyncRunLog}) and counted on the
 * {@see SyncRun}. The Messenger/RLS-GUC entry point is APIC-P3-05.
 */
final readonly class InboundSyncRunner
{
    public function __construct(
        private FieldMappingRepositoryInterface $mappings,
        private RecordMapper $mapper,
        private PaginatedFetcher $fetcher,
        private CursorManager $cursors,
        private RecordSelector $selector,
        private InboundRecordWriter $writer,
        private SyncRunRepositoryInterface $runs,
        private EntityManagerInterface $em,
        private TenantContext $tenantContext,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function run(SyncBinding $binding): SyncRun
    {
        $run = new SyncRun($binding, SyncDirection::Inbound);
        $run->setCursorBefore($binding->getCursor());
        $this->runs->save($run);
        $runId = $run->getId();
        $bindingId = $binding->getId();

        $readEndpoint = $binding->getReadEndpoint();
        if (null === $readEndpoint) {
            $run->markFinished(SyncRunStatus::Failed);
            $this->runs->save($run);

            return $run;
        }

        $mappings = $this->scopedMappings($binding, $readEndpoint);
        $cursorField = $this->cursors->current($binding)?->field;

        // The fetcher reads scalar descriptor fields off the connection + read
        // endpoint captured here; both stay usable after the per-page clear
        // (detached entities are still readable), so the generator is created
        // once from the pre-clear references.
        foreach ($this->fetcher->pages($binding->getConnection(), $readEndpoint) as $page) {
            foreach ($page as $record) {
                $this->processRecord($run, $binding, $mappings, $record);
                $this->em->flush();
            }

            if (null !== $cursorField) {
                $this->advanceCursor($binding, $page, $cursorField);
            }

            // FrankenPHP worker hygiene (PaginatedFetcher docblock): clear the
            // unit of work between pages so a 50k-record pull stays O(page), not
            // O(total) — the per-record SyncRunLog and the upserted catalog
            // objects would otherwise pin the whole run in the identity map.
            // Reload the entities the loop keeps mutating and re-point the tenant
            // context so the listener can still stamp tenant_id on new rows.
            $this->em->flush();
            $this->em->clear();
            $binding = $this->reload($bindingId);
            $run = $this->reloadRun($runId);
            $mappings = $this->scopedMappings($binding, $readEndpoint);
        }

        $run->markFinished(null, $binding->getCursor());
        $this->runs->save($run);

        return $run;
    }

    /**
     * Mappings scoped to the read endpoint: endpoint-less mappings apply
     * everywhere, endpoint-scoped ones only to their own operation (#2634).
     *
     * @return list<FieldMapping>
     */
    private function scopedMappings(SyncBinding $binding, RemoteEndpoint $readEndpoint): array
    {
        return array_values(array_filter(
            $this->mappings->findByConnection($binding->getConnection()),
            static fn (FieldMapping $m): bool => $m->appliesToEndpoint($readEndpoint->getId()),
        ));
    }

    private function reload(Uuid $bindingId): SyncBinding
    {
        $binding = $this->em->find(SyncBinding::class, $bindingId->toRfc4122());
        if (!$binding instanceof SyncBinding) {
            throw new RuntimeException('Sync binding vanished mid-run.');
        }

        // The clear detached the tenant; re-bind the managed one so persisting
        // the next page's SyncRunLog rows still stamps tenant_id (TenantFilter).
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
     * @param list<FieldMapping>      $mappings
     * @param array<array-key, mixed> $record
     */
    private function processRecord(SyncRun $run, SyncBinding $binding, array $mappings, array $record): void
    {
        $mapped = $this->mapper->map($record, $mappings);
        if (null === $mapped) {
            $run->recordSkipped();
            $this->log($run, SyncRecordAction::Skipped, null, null, 'No match key or empty match value.');

            return;
        }

        $result = $this->writer->upsert(
            $binding->getObjectTypeId(),
            $mapped->matchAttributeCode,
            $mapped->matchValue,
            $mapped->attributeValues,
        );

        $action = $this->recordOutcome($run, $result);
        $this->log($run, $action, $mapped->matchValue, $mapped->attributeValues, $this->message($result));
    }

    private function recordOutcome(SyncRun $run, InboundUpsertResult $result): SyncRecordAction
    {
        switch ($result->action) {
            case 'created':
                $run->recordCreated();

                return SyncRecordAction::Created;
            case 'updated':
                $run->recordUpdated();

                return SyncRecordAction::Updated;
            case 'skipped':
                $run->recordSkipped();

                return SyncRecordAction::Skipped;
            default:
                $run->recordFailed();

                return SyncRecordAction::Failed;
        }
    }

    private function message(InboundUpsertResult $result): ?string
    {
        return [] === $result->issues ? null : implode('; ', $result->issues);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function log(SyncRun $run, SyncRecordAction $action, ?string $matchKey, ?array $fields, ?string $message): void
    {
        $log = new SyncRunLog($run, $action);
        $log->setMatchKey($matchKey);
        $log->setFields($fields);
        $log->setMessage($message);
        $this->em->persist($log);
    }

    /**
     * Advances the cursor to the last record's cursor value on the page; the
     * monotonic guard in CursorManager rejects an out-of-order page.
     *
     * @param list<array<array-key, mixed>> $page
     */
    private function advanceCursor(SyncBinding $binding, array $page, string $cursorField): void
    {
        for ($i = \count($page) - 1; $i >= 0; --$i) {
            $value = $this->selector->value($page[$i], $cursorField);
            if (\is_string($value) || \is_int($value) || \is_float($value)) {
                $this->cursors->advance($binding, (string) $value);

                return;
            }
        }

        $this->logger->debug('No cursor value found on page; cursor unchanged.', [
            'binding' => $binding->getId()->toRfc4122(),
            'field' => $cursorField,
        ]);
    }
}
