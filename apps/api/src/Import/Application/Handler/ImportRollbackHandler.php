<?php

declare(strict_types=1);

namespace App\Import\Application\Handler;

use App\Import\Application\Service\ImportRollbackService;
use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Message\ImportRollbackMessage;
use App\Import\Domain\Repository\ImportSessionRepositoryInterface;
use App\Shared\Application\BulkOperationInProgressException;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * #2818 — worker entry point for "Wycofaj import".
 *
 * Undoing an import used to happen inside the request that asked for it, which
 * capped it at whatever fits in 30 seconds — about 700 objects. Past that the
 * connection closed, the transaction unwound, and the operator was left with a
 * 24-hour rollback window that could not be exercised on the imports most
 * likely to need it.
 *
 * Dispatched on `messenger.bus.long_running` so the run manages its own
 * transaction boundaries: {@see ImportRollbackService::run()} commits per chunk
 * and checkpoints, which is what makes a ten-minute undo both observable and
 * resumable. Under the default bus's blanket transaction it would be neither.
 *
 * A run that stops short (operator cancelled, chunk failed) leaves the session
 * `rolling_back` with its checkpoint — re-triggering continues from there.
 */
#[AsMessageHandler]
final readonly class ImportRollbackHandler
{
    public function __construct(
        private ImportSessionRepositoryInterface $sessions,
        private ImportRollbackService $rollbackService,
        private TenantContext $tenantContext,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ImportRollbackMessage $message): void
    {
        // A long undo must not be cut short by the worker's request time limit
        // (FrankenPHP resets max_execution_time per message).
        set_time_limit(0);

        $session = $this->sessions->findById($message->importSessionId);
        if (!$session instanceof ImportSession) {
            return;
        }

        $tenant = $session->getTenant();
        if (!$tenant instanceof Tenant) {
            $this->logger->error('Import session {id} has no tenant; rollback dropped.', [
                'id' => $message->importSessionId->toRfc4122(),
            ]);

            return;
        }
        $this->tenantContext->set($tenant);

        try {
            $outcome = $this->rollbackService->run($session);
        } catch (BulkOperationInProgressException $exception) {
            // An import (or another bulk job) holds the per-tenant lock. Retry
            // with the transport's backoff rather than failing the rollback —
            // the undo-log is untouched, so a later attempt is equivalent.
            throw new RecoverableMessageHandlingException(
                $exception->getMessage().' Rollback will retry.',
                previous: $exception,
            );
        } catch (LogicException $exception) {
            // Not rollbackable any more (window closed, already rolled back, a
            // structural session): retrying cannot help, so record and stop.
            $this->logger->error('Rollback of import session {id} refused: {reason}', [
                'id' => $message->importSessionId->toRfc4122(),
                'reason' => $exception->getMessage(),
            ]);

            return;
        }

        if (!$outcome['completed']) {
            $this->logger->info('Rollback of import session {id} stopped after {done}/{total} objects ({reason}).', [
                'id' => $message->importSessionId->toRfc4122(),
                'done' => $outcome['objectsDone'],
                'total' => $outcome['objectsTotal'],
                'reason' => $outcome['stoppedReason'] ?? 'unknown',
            ]);
        }
    }
}
