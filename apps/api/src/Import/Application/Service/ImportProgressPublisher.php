<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Domain\Entity\ImportSession;
use App\Shared\Infrastructure\Mercure\MercureSubscribeTopics;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Throwable;

use const JSON_THROW_ON_ERROR;

/**
 * Publishes import lifecycle / progress updates to two tenant-scoped,
 * private Mercure topics (spec §8.5; AUD-001 #1573):
 *   - `tenant/{tid}/imports/user/{user_id}` — broadcast list view
 *   - `tenant/{tid}/imports/{session_id}` — detail (progress %, SKU, errors)
 *
 * The `tenant/{tid}` prefix + `private: true` close the AUD-001
 * cross-tenant leak: the hub only delivers a private update to a
 * subscriber whose JWT authorises that exact tenant topic.
 *
 * Hub failures are logged but never abort the underlying write — Mercure
 * is a notification channel, not the source of truth (mirrors the
 * Catalog publisher's stance).
 */
final class ImportProgressPublisher
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HubInterface $hub,
        private readonly string $topicBase,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function progress(ImportSession $session, int $processedRows, ?string $currentSku = null): void
    {
        $totalRows = $session->getTotalRows() ?? 0;
        $this->publish($session, 'progress', [
            'processed_rows' => $processedRows,
            'total_rows' => $totalRows,
            'success_count' => $session->getSuccessCount(),
            'error_count' => $session->getErrorCount(),
            'current_sku' => $currentSku,
        ]);
    }

    public function error(ImportSession $session, int $rowNumber, ?string $sku, string $errorType, string $message): void
    {
        $this->publish($session, 'error', [
            'row_number' => $rowNumber,
            'sku' => $sku,
            'error_type' => $errorType,
            'message' => $message,
        ]);
    }

    /**
     * #2818 — the rollback runs in the worker now, so it publishes progress the
     * way the import does. Same detail topic: the session screen shows one
     * timeline whether the session is being written or being undone.
     */
    public function rollbackProgress(ImportSession $session, int $objectsDone, int $objectsTotal, string $phase): void
    {
        $this->publish($session, 'rollback_progress', [
            'objects_done' => $objectsDone,
            'objects_total' => $objectsTotal,
            'phase' => $phase,
            'status' => $session->getStatus()->value,
        ]);
    }

    /**
     * #2818 — a rollback that stopped short (cancelled or interrupted). Its own
     * event type rather than `completed`: the catalogue is partly undone, and
     * the UI must not report that as a finished rollback.
     */
    public function rollbackStopped(ImportSession $session, int $objectsDone, int $objectsTotal, string $reason): void
    {
        $this->publish($session, 'rollback_stopped', [
            'objects_done' => $objectsDone,
            'objects_total' => $objectsTotal,
            'reason' => $reason,
            'status' => $session->getStatus()->value,
        ]);
    }

    public function completed(ImportSession $session): void
    {
        $this->publish($session, 'completed', [
            'status' => $session->getStatus()->value,
            'success_count' => $session->getSuccessCount(),
            'error_count' => $session->getErrorCount(),
            'total_rows' => $session->getTotalRows() ?? 0,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function publish(ImportSession $session, string $type, array $payload): void
    {
        $tenant = $session->getTenant();
        if (null === $tenant) {
            // A session is always tenant-stamped before processing; a null
            // here means a misconfigured caller. Skipping the publish is
            // safer than emitting an un-scoped (cross-tenant) topic.
            $this->logger->warning('Mercure import progress publish skipped: session has no tenant', [
                'session_id' => $session->getId()->toRfc4122(),
                'type' => $type,
            ]);

            return;
        }
        $tenantId = $tenant->getId();

        $sessionTopic = MercureSubscribeTopics::importSession($tenantId, $this->topicBase, $session->getId()->toRfc4122());
        $userTopic = MercureSubscribeTopics::importUser($tenantId, $this->topicBase, $session->getUserId()->toRfc4122());

        $update = new Update(
            topics: [$sessionTopic, $userTopic],
            data: json_encode([
                'type' => $type,
                'session_id' => $session->getId()->toRfc4122(),
                'data' => $payload,
            ], JSON_THROW_ON_ERROR),
            private: true,
        );

        try {
            $this->hub->publish($update);
        } catch (Throwable $exception) {
            $this->logger->warning('Mercure import progress publish failed: {message}', [
                'message' => $exception->getMessage(),
                'session_id' => $session->getId()->toRfc4122(),
                'type' => $type,
            ]);
        }
    }
}
