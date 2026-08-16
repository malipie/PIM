<?php

declare(strict_types=1);

namespace App\Export\Presentation\Controller;

use App\Export\Application\Async\ExportProgressPublisher;
use App\Export\Domain\Entity\ExportLog;
use App\Export\Domain\Entity\ExportProfile;
use App\Export\Domain\Entity\ExportSession;
use App\Export\Domain\Enum\ExportSource;
use App\Export\Domain\Enum\ExportStatus;
use App\Export\Domain\Message\RunExportMessage;
use App\Export\Domain\Repository\ExportLogRepositoryInterface;
use App\Export\Domain\Repository\ExportProfileRepositoryInterface;
use App\Export\Domain\Repository\ExportSessionRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Contracts\Audit\DataExportAuditor;
use App\Shared\Application\TenantContext;
use App\Shared\Application\UserIdentityAware;
use DateTimeInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * EXP-08 (#587) — ExportSession lifecycle endpoints.
 *
 * Self-audit only (PRD §8.5) — every read scopes by `user_id = current
 * user`. Cross-user reads return 404 (information hiding).
 *
 * Endpoints:
 *   - GET    /api/exports/sessions
 *   - GET    /api/exports/sessions/{id}
 *   - GET    /api/exports/sessions/{id}/status
 *   - GET    /api/exports/sessions/{id}/download
 *   - POST   /api/exports/sessions/{id}/rerun
 *   - POST   /api/exports/profiles/{id}/run     (Run now from profile)
 *   - DELETE /api/exports/sessions/{id}
 *
 * Download streams the file from MinIO through PHP (no presigned URL
 * in MVP — keeps tenant scoping on the backend; presigned URL is a
 * Faza 1 follow-up tied to bandwidth offload).
 */
final class ExportSessionController
{
    private const string UUID_REGEX = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly ExportSessionRepositoryInterface $sessions,
        private readonly ExportLogRepositoryInterface $logs,
        private readonly ExportProfileRepositoryInterface $profiles,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
        private readonly MessageBusInterface $bus,
        private readonly FilesystemOperator $exportsStorage,
        private readonly ExportProgressPublisher $progress,
        private readonly DataExportAuditor $dataExportAuditor,
    ) {
    }

    #[Route(
        path: '/api/exports/sessions',
        name: 'pim_export_sessions_list',
        methods: ['GET'],
    )]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all')]
    public function list(): JsonResponse
    {
        [$tenant, $userId] = $this->resolveTenantAndUser();
        $rows = $this->sessions->findByTenantAndUser($tenant, $userId);

        return new JsonResponse([
            'items' => array_map([$this, 'serializeSummary'], $rows),
            'total' => \count($rows),
        ]);
    }

    #[Route(
        path: '/api/exports/sessions/{id}',
        name: 'pim_export_sessions_get',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['GET'],
    )]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all')]
    public function get(string $id): JsonResponse
    {
        $session = $this->loadOwnedOrFail($id);
        $payload = $this->serializeFull($session);
        $payload['logs'] = array_map([$this, 'serializeLog'], $this->logs->findRecentForSession($session));

        return new JsonResponse($payload);
    }

    #[Route(
        path: '/api/exports/sessions/{id}/status',
        name: 'pim_export_sessions_status',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['GET'],
    )]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all')]
    public function status(string $id): JsonResponse
    {
        $session = $this->loadOwnedOrFail($id);

        return new JsonResponse([
            'id' => $session->getId()->toRfc4122(),
            'status' => $session->getStatus()->value,
            'rows_done' => $session->getSuccessCount(),
            'rows_total' => $session->getTargetCount(),
            'progress_pct' => $this->progressPct($session),
            'error_message' => $session->getErrorMessage(),
        ]);
    }

    #[Route(
        path: '/api/exports/sessions/{id}/download',
        name: 'pim_export_sessions_download',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['GET'],
    )]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all')]
    public function download(string $id): Response
    {
        $session = $this->loadOwnedOrFail($id);
        if (ExportStatus::Done !== $session->getStatus()) {
            throw new ConflictHttpException(sprintf(
                'Export %s is in status "%s" — download is only available for status=done.',
                $id,
                $session->getStatus()->value,
            ));
        }
        $remotePath = $session->getFilePath();
        if (null === $remotePath || '' === $remotePath) {
            throw new NotFoundHttpException(sprintf('Export %s has no file attached.', $id));
        }

        try {
            $stream = $this->exportsStorage->readStream($remotePath);
        } catch (FilesystemException $error) {
            throw new NotFoundHttpException(sprintf('Export file for %s is missing from storage.', $id), $error);
        }

        // AUD-052 — dedicated `data_export` audit event: full product data + PII
        // is leaving the system. Recorded once the file stream is open (the
        // download will proceed) so the compliance trail captures who exported
        // what, when. Written before the streamed body so it persists even if
        // the client aborts mid-stream.
        $this->dataExportAuditor->recordExport($session->getId()->toRfc4122(), [
            'entity_type' => $session->getEntityType()->value,
            'object_type_id' => $session->getObjectTypeId()?->toRfc4122(),
            'format' => $session->getFormat()->value,
            'target_scope' => $session->getTargetScope()->value,
            'row_count' => $session->getSuccessCount(),
            'locales' => $session->getLocales(),
            'channels' => $session->getChannels(),
        ]);

        $filename = sprintf('pim-export-%s.%s', $session->getStartedAt()->format('Ymd-His'), $session->getFormat()->value);

        $response = new StreamedResponse(static function () use ($stream): void {
            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if (false === $chunk) {
                    break;
                }
                echo $chunk;
                flush();
            }
            fclose($stream);
        });
        $response->headers->set('Content-Type', $this->contentTypeFor($session));
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    #[Route(
        path: '/api/exports/sessions/{id}/rerun',
        name: 'pim_export_sessions_rerun',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['POST'],
    )]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'run')]
    public function rerun(string $id): JsonResponse
    {
        $source = $this->loadOwnedOrFail($id);
        $tenant = $source->getTenant();
        if (null === $tenant) {
            throw new ConflictHttpException('Source session is missing tenant context — cannot rerun.');
        }

        $clone = new ExportSession(
            userId: $source->getUserId(),
            source: ExportSource::ListContext,
            format: $source->getFormat(),
            targetScope: $source->getTargetScope(),
            selectedColumns: $source->getSelectedColumns(),
            encoding: $source->getEncoding(),
            filterSnapshot: $source->getFilterSnapshot(),
            selectedObjectIds: $source->getSelectedObjectIds(),
            locales: $source->getLocales(),
            channels: $source->getChannels(),
            includeVariants: $source->includesVariants(),
            entityType: $source->getEntityType(),
            objectTypeId: $source->getObjectTypeId(),
        );
        $clone->assignTenant($tenant);
        $this->sessions->save($clone);
        $this->bus->dispatch(new RunExportMessage($clone->getId(), $tenant->getId()));

        return new JsonResponse(
            data: $this->serializeSummary($clone),
            status: Response::HTTP_ACCEPTED,
            headers: ['Location' => sprintf('/api/exports/sessions/%s', $clone->getId()->toRfc4122())],
        );
    }

    /**
     * EXR-15 — user-requested cancellation. Persists the terminal status;
     * the async handler polls it between chunks and stops gracefully
     * (partial temp file removed, no error state).
     */
    #[Route(
        path: '/api/exports/sessions/{id}/cancel',
        name: 'pim_export_sessions_cancel',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['POST'],
    )]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'run')]
    public function cancel(string $id): JsonResponse
    {
        $session = $this->loadOwnedOrFail($id);
        if ($session->getStatus()->isTerminal()) {
            throw new ConflictHttpException(sprintf(
                'Export session is already %s and cannot be cancelled.',
                $session->getStatus()->value,
            ));
        }

        $session->markCancelled();
        $this->sessions->save($session);
        $this->progress->status($session);

        return new JsonResponse($this->serializeSummary($session));
    }

    #[Route(
        path: '/api/exports/profiles/{id}/run',
        name: 'pim_export_profiles_run',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['POST'],
    )]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'run')]
    public function runFromProfile(string $id): JsonResponse
    {
        [$tenant, $userId] = $this->resolveTenantAndUser();
        $profile = $this->profiles->findById(Uuid::fromString($id));
        if (!$profile instanceof ExportProfile || !$profile->isOwnedBy($userId)) {
            throw new NotFoundHttpException(sprintf('Export profile "%s" was not found.', $id));
        }

        // EXR-04: generation for non-product types lands in EXR-05/06 — reject
        // running such profiles with a clear 422 instead of dispatching a run
        // that would fail mid-stream.
        if (!$profile->getEntityType()->isExecutable()) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Export of entity_type=%s is not implemented yet (delivered in EXR-05/EXR-06).',
                $profile->getEntityType()->value,
            ));
        }

        $session = $this->sessionFromProfile($profile, $tenant);
        $this->sessions->save($session);
        $this->bus->dispatch(new RunExportMessage($session->getId(), $tenant->getId()));
        $profile->recordRun();
        $this->profiles->save($profile);

        return new JsonResponse(
            data: $this->serializeSummary($session),
            status: Response::HTTP_ACCEPTED,
            headers: ['Location' => sprintf('/api/exports/sessions/%s', $session->getId()->toRfc4122())],
        );
    }

    #[Route(
        path: '/api/exports/sessions/{id}',
        name: 'pim_export_sessions_delete',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['DELETE'],
    )]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'integration', action: 'admin', anyOf: [
        'integration.admin',
        'exports.view_all',
    ])]
    public function delete(string $id): Response
    {
        $session = $this->loadOwnedOrFail($id);

        $remotePath = $session->getFilePath();
        if (null !== $remotePath && '' !== $remotePath) {
            try {
                $this->exportsStorage->delete($remotePath);
            } catch (FilesystemException) {
                // Storage already gone — proceed with DB delete so the
                // user can unstick a dangling row.
            }
        }
        $this->sessions->remove($session);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array{0: \App\Shared\Domain\Tenant, 1: Uuid}
     */
    private function resolveTenantAndUser(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof UserIdentityAware) {
            throw new AccessDeniedHttpException('Authenticated user identity required.');
        }
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new AccessDeniedHttpException('Tenant context required.');
        }

        return [$tenant, $user->getId()];
    }

    private function loadOwnedOrFail(string $id): ExportSession
    {
        $session = $this->sessions->findById(Uuid::fromString($id));
        if (null === $session) {
            throw new NotFoundHttpException(sprintf('Export session "%s" was not found.', $id));
        }
        [, $userId] = $this->resolveTenantAndUser();
        if (!$session->isSelfOwnedBy($userId)) {
            throw new NotFoundHttpException(sprintf('Export session "%s" was not found.', $id));
        }

        return $session;
    }

    private function sessionFromProfile(ExportProfile $profile, \App\Shared\Domain\Tenant $tenant): ExportSession
    {
        $config = $profile->getConfig();
        $selectedColumns = $config['selected_columns'] ?? [];
        if (!\is_array($selectedColumns) || [] === $selectedColumns) {
            throw new ConflictHttpException('Profile config is missing selected_columns.');
        }
        $columns = [];
        foreach ($selectedColumns as $col) {
            if (\is_string($col) && '' !== $col) {
                $columns[] = $col;
            }
        }
        $format = isset($config['format']) && \is_string($config['format'])
            ? \App\Export\Domain\Enum\ExportFormat::tryFrom($config['format']) ?? \App\Export\Domain\Enum\ExportFormat::Xlsx
            : \App\Export\Domain\Enum\ExportFormat::Xlsx;
        $targetScope = isset($config['default_target_scope']) && \is_string($config['default_target_scope'])
            ? \App\Export\Domain\Enum\ExportTargetScope::tryFrom($config['default_target_scope']) ?? \App\Export\Domain\Enum\ExportTargetScope::All
            : \App\Export\Domain\Enum\ExportTargetScope::All;
        $encoding = isset($config['encoding']) && \is_string($config['encoding'])
            ? \App\Export\Domain\Enum\ExportEncoding::tryFrom($config['encoding'])
            : null;

        $session = new ExportSession(
            userId: $profile->getUserId(),
            source: ExportSource::SavedProfileRun,
            format: $format,
            targetScope: $targetScope,
            selectedColumns: $columns,
            profile: $profile,
            encoding: $encoding,
            locales: $this->stringArrayOrNull($config['locales'] ?? null),
            channels: $this->stringArrayOrNull($config['channels'] ?? null),
            includeVariants: \is_bool($config['include_variants'] ?? null) ? $config['include_variants'] : true,
            entityType: $profile->getEntityType(),
            objectTypeId: $profile->getObjectTypeId(),
        );
        $session->assignTenant($tenant);

        return $session;
    }

    /**
     * @return list<string>|null
     */
    private function stringArrayOrNull(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $v) {
            if (\is_string($v) && '' !== $v) {
                $out[] = $v;
            }
        }

        return [] === $out ? null : $out;
    }

    private function progressPct(ExportSession $session): int
    {
        $total = $session->getTargetCount();
        if ($total <= 0) {
            return 0;
        }

        return (int) floor($session->getSuccessCount() / $total * 100);
    }

    private function contentTypeFor(ExportSession $session): string
    {
        return match ($session->getFormat()) {
            \App\Export\Domain\Enum\ExportFormat::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            \App\Export\Domain\Enum\ExportFormat::Xml => 'application/xml; charset=utf-8',
            \App\Export\Domain\Enum\ExportFormat::Csv => sprintf(
                'text/csv; charset=%s',
                \App\Export\Domain\Enum\ExportEncoding::Windows1250 === $session->getEncoding() ? 'windows-1250' : 'utf-8',
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSummary(ExportSession $session): array
    {
        return [
            'id' => $session->getId()->toRfc4122(),
            'entity_type' => $session->getEntityType()->value,
            'object_type_id' => $session->getObjectTypeId()?->toRfc4122(),
            'format' => $session->getFormat()->value,
            'target_scope' => $session->getTargetScope()->value,
            'target_count' => $session->getTargetCount(),
            'success_count' => $session->getSuccessCount(),
            'status' => $session->getStatus()->value,
            'source' => $session->getSource()->value,
            'started_at' => $session->getStartedAt()->format(DateTimeInterface::ATOM),
            'completed_at' => $session->getCompletedAt()?->format(DateTimeInterface::ATOM),
            // EXR-08 — history table needs these without fetching each
            // session's detail: file name column, profile column,
            // duration column and top-errors KPI.
            'profile_name' => $session->getProfile()?->getName(),
            'file_path' => $session->getFilePath(),
            'duration_ms' => $session->getDurationMs(),
            'error_message' => $session->getErrorMessage(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFull(ExportSession $session): array
    {
        return $this->serializeSummary($session) + [
            'encoding' => $session->getEncoding()?->value,
            'selected_columns' => $session->getSelectedColumns(),
            'selected_object_ids' => $session->getSelectedObjectIds(),
            'filter_snapshot' => $session->getFilterSnapshot(),
            'locales' => $session->getLocales(),
            'channels' => $session->getChannels(),
            'include_variants' => $session->includesVariants(),
            'file_size_bytes' => $session->getFileSizeBytes(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLog(ExportLog $log): array
    {
        return [
            'id' => $log->getId()->toRfc4122(),
            'level' => $log->getLevel()->value,
            'message' => $log->getMessage(),
            'context' => $log->getContext(),
            'created_at' => $log->getCreatedAt()->format(DateTimeInterface::ATOM),
        ];
    }
}
