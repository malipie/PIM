<?php

declare(strict_types=1);

namespace App\Import\Presentation\Controller;

use App\Backup\Domain\Entity\Backup;
use App\Backup\Domain\Enum\BackupStatus;
use App\Backup\Domain\Repository\BackupRepositoryInterface;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Entity\User;
use App\Import\Application\Handler\ImportRunHandler;
use App\Import\Application\Service\Archive\ArchiveSecurityException;
use App\Import\Application\Service\Archive\XlsxArchiveGuard;
use App\Import\Application\Service\StagedFileService;
use App\Import\Domain\Entity\ImportProfile;
use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Entity\StagedFile;
use App\Import\Domain\Enum\ImportImageSource;
use App\Import\Domain\Enum\ImportMode;
use App\Import\Domain\Message\ImportRunMessage;
use App\Import\Domain\Repository\ImportProfileRepositoryInterface;
use App\Import\Domain\Repository\ImportSessionRepositoryInterface;
use App\Shared\Application\BulkOperationInProgressException;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Messenger\Stamp\TenantStamp;
use DateTimeInterface;
use InvalidArgumentException;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * IMP-04 (#445) wizard Step 4 confirm — uploads the source file to the
 * imports bucket, persists an {@see ImportSession}, and either runs
 * the import inline (rows < 50, spec §3 sync threshold) or hands it
 * off to the async worker via {@see ImportRunMessage}.
 *
 * Multipart fields:
 *   - `file` — CSV / xlsx
 *   - `target_object_type_id` — UUID
 *   - `mapping` — JSON `{column_header: attribute_code | "skip"}`
 *   - `profile_id` — UUID, optional
 *   - `locale`, `encoding`, `delimiter` — optional overrides
 *   - `do_backup` — boolean, optional. When `1`/`true` a `backup_id` of an
 *     already-`completed` {@see Backup} is required (IMP2-2.10 #1486): the FE
 *     wizard triggers the pre-import snapshot via the Backup module and gates
 *     its CTA on `completed`, then passes the id here so the session records
 *     which snapshot preceded it.
 *   - `backup_id` — UUID, required when `do_backup=1`; must be a completed
 *     backup owned by the caller's tenant.
 */
final class StartImportController
{
    private const int SYNC_THRESHOLD_ROWS = 50;

    /** IMP2-1.13 — server-side ZIP upload cap (D13). */
    private const int MAX_ZIP_BYTES = 500 * 1024 * 1024;

    /** IMP2-2.7 (#1483) — D10 application defaults when a tenant sets no override. */
    private const int DEFAULT_MAX_ROWS = 200_000;
    private const int DEFAULT_MAX_FILE_BYTES = 100 * 1024 * 1024;

    public function __construct(
        private readonly ImportSessionRepositoryInterface $sessions,
        private readonly AttributeRepositoryInterface $attributes,
        private readonly ImportProfileRepositoryInterface $profiles,
        private readonly ObjectTypeRepositoryInterface $objectTypes,
        private readonly ImportRunHandler $runHandler,
        private readonly MessageBusInterface $bus,
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly FilesystemOperator $importsStorage,
        private readonly StagedFileService $stagedFiles,
        private readonly RateLimiterFactoryInterface $importTriggerLimiter,
        private readonly XlsxArchiveGuard $xlsxArchiveGuard,
        private readonly BackupRepositoryInterface $backups,
    ) {
    }

    #[Route(
        path: '/api/import-sessions',
        name: 'imports_start',
        methods: ['POST'],
    )]
    #[RequiresPermission(module: 'imports', action: 'run')]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('JWT', 'Authenticated user required.');
        }
        $tenant = $user->getTenant();
        $this->tenantContext->set($tenant);

        // IMP2-2.7 (#1483) — per-tenant rate limit. Mirrors the backup_trigger
        // pattern: sliding window keyed by tenant, 429 + Retry-After on overflow.
        $reservation = $this->importTriggerLimiter->create($tenant->getId()->toRfc4122())->consume();
        if (!$reservation->isAccepted()) {
            throw new TooManyRequestsHttpException(
                max(0, $reservation->getRetryAfter()->getTimestamp() - time()),
                'Przekroczono limit importów dla tego tenanta. Spróbuj ponownie później.',
            );
        }

        // IMP2-2.2 — accept a staged_file_id (uploaded once at parse-preview)
        // or, for back-compat, a fresh multipart `file`. With a staged id the
        // bytes already live on MinIO; we pull a local copy only to count rows
        // and then server-side-copy them to the session key (no re-upload).
        $stagedFile = $this->resolveStagedFile($request, $user);
        $file = $request->files->get('file');
        if (null === $stagedFile && !$file instanceof UploadedFile) {
            throw new BadRequestHttpException('Either "staged_file_id" or a "file" multipart field is required.');
        }
        $localPath = null !== $stagedFile
            ? $this->stagedFiles->downloadToTemp($stagedFile)
            : $file->getPathname();
        $originalName = null !== $stagedFile ? $stagedFile->getFileName() : $file->getClientOriginalName();
        if ('' === $originalName) {
            $originalName = 'upload.csv';
        }
        $fileSizeBytes = null !== $stagedFile ? $stagedFile->getSizeBytes() : (int) $file->getSize();

        // IMP2-2.7 (#1483) — file-size guardrail (D10 default 100 MB, per-tenant
        // override). RFC 7807 422 instead of the raw PHP upload_max_filesize 400.
        $maxFileBytes = $tenant->getImportMaxFileSize() ?? self::DEFAULT_MAX_FILE_BYTES;
        if ($fileSizeBytes > $maxFileBytes) {
            throw new UnprocessableEntityHttpException(\sprintf(
                'Plik (%d B) przekracza limit %d MB.',
                $fileSizeBytes,
                intdiv($maxFileBytes, 1024 * 1024),
            ));
        }

        // IMP2-2.8 (#1484) — zip-bomb guard on XLSX before parsing/persisting.
        if (str_ends_with(strtolower($originalName), '.xlsx')) {
            try {
                $this->xlsxArchiveGuard->validate($localPath);
            } catch (ArchiveSecurityException $exception) {
                throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
            }
        }

        $targetId = $this->parseUuid($request->request->get('target_object_type_id'), 'target_object_type_id');
        $objectType = $this->objectTypes->findById($targetId);
        if (!$objectType instanceof ObjectType) {
            throw new NotFoundHttpException(\sprintf('ObjectType "%s" was not found.', $targetId->toRfc4122()));
        }

        $mapping = $this->parseMapping($request->request->get('mapping', '{}'));
        $profile = $this->resolveProfile($request->request->get('profile_id'), $tenant, $user->getId(), $mapping, $objectType);

        // IMP2-2.10 (#1486) — when the wizard's "create a full backup" box is
        // ticked it triggers the snapshot via the Backup module and gates its
        // CTA on `completed`, then forwards the backup_id here. Validate it
        // up-front (fail fast before persistence) and link it to the session.
        $backup = $this->resolveBackup($request, $tenant);

        // IMP2-1.6a (#1468, ADR-0019 D8): the sync/async split counts DATA
        // ROWS, not bytes — a wide 20-row CSV stays inline, a narrow 500-row
        // one goes to the worker. Counting stops at the threshold; the +1
        // header line is excluded.
        // IMP2-2.7 (#1483) — row-count guardrail (D10 default 200k, per-tenant
        // override). For CSV we count on the stream up to maxRows+1 (cheap fgets,
        // no parsing) and reject before any persistence; XLSX row-limit is caught
        // at parse-preview (the parser yields totalRows there). The same count
        // drives the sync/async split (<= 50 rows runs inline).
        $maxRows = $tenant->getImportMaxRows() ?? self::DEFAULT_MAX_ROWS;
        $dataRowCount = str_ends_with(strtolower($originalName), '.csv')
            ? $this->countDataRows($localPath, $maxRows + 1)
            // XLSX is a zip — line counting is meaningless; spreadsheets
            // always take the worker path (cheap now that dev runs one).
            : self::SYNC_THRESHOLD_ROWS + 1;
        if ($dataRowCount > $maxRows) {
            throw new UnprocessableEntityHttpException(\sprintf(
                'Plik przekracza limit %d wierszy.',
                $maxRows,
            ));
        }
        // IMP2-1.13 — optional ZIP of images. Validate extension + 500 MB cap
        // server-side (FE pre-validates too); the bytes are streamed to MinIO
        // after the session is persisted.
        $zipFile = $request->files->get('zip_file');
        $zipName = null;
        $zipSize = null;
        if ($zipFile instanceof UploadedFile) {
            $zipName = $zipFile->getClientOriginalName();
            if ('' === $zipName || !str_ends_with(strtolower($zipName), '.zip')) {
                throw new BadRequestHttpException('"zip_file" must be a .zip archive.');
            }
            $zipSize = (int) $zipFile->getSize();
            if ($zipSize > self::MAX_ZIP_BYTES) {
                throw new UnprocessableEntityHttpException(\sprintf('ZIP exceeds the %d MB limit.', self::MAX_ZIP_BYTES >> 20));
            }
        }

        $session = new ImportSession(
            userId: $user->getId(),
            targetObjectType: $objectType,
            fileName: $originalName,
            fileSizeBytes: $fileSizeBytes,
            profile: $profile,
            zipFileName: $zipName,
            zipFileSizeBytes: $zipSize,
        );

        $mode = $this->resolveMode($request->request->get('mode'), $profile);
        $matchAttributeCode = $this->resolveMatchAttributeCode(
            $request->request->get('match_attribute_code'),
            $profile,
            $tenant,
        );
        $session->configureRun($mode, $matchAttributeCode);
        $session->setColumnMapping($mapping);
        // IMP2-1.13 — a ZIP forces zip mode; otherwise honour the wizard's
        // image_source (http|none). http behaves like none for the engine
        // (URL cells are auto-detected), so only `zip` changes routing.
        $session->setImageSource(
            null !== $zipName
                ? ImportImageSource::Zip
                : (ImportImageSource::tryFrom((string) $request->request->get('image_source', 'none')) ?? ImportImageSource::None),
        );
        // #1718 — opt-in: mint missing select/multiselect options during the
        // run. Explicit form flag wins; otherwise inherit the saved profile.
        $session->setCreateMissingOptions(
            $this->resolveCreateMissingOptions($request->request->get('create_missing_options'), $profile),
        );
        if ($backup instanceof Backup) {
            $session->setBackupSnapshot($backup);
        }

        // Persist before upload so a later upload failure has a session id
        // to attach to (status stays `pending`, error_message captures
        // the reason). The TenantAssignmentListener stamps tenant_id on
        // pre-persist.
        $this->sessions->save($session);

        $remotePath = \sprintf(
            '%s/%s/%s',
            $tenant->getId()->toRfc4122(),
            $session->getId()->toRfc4122(),
            $session->getFileName(),
        );
        try {
            if (null !== $stagedFile) {
                // Bytes already on MinIO — server-side copy to the session key,
                // no client/byte re-transfer.
                $this->stagedFiles->copyToKey($stagedFile, $remotePath);
            } else {
                $stream = fopen($localPath, 'r');
                if (false === $stream) {
                    throw new RuntimeException('Failed to open uploaded file for reading.');
                }
                try {
                    $this->importsStorage->writeStream($remotePath, $stream);
                } finally {
                    if (\is_resource($stream)) {
                        fclose($stream);
                    }
                }
            }
        } catch (FilesystemException|RuntimeException $exception) {
            $session->markFailed(\sprintf('Failed to stage uploaded file: %s', $exception->getMessage()));
            $this->sessions->save($session);
            throw new BadRequestHttpException('Failed to stage the uploaded file.', $exception);
        } finally {
            // The staged temp copy was only needed for row counting.
            if (null !== $stagedFile) {
                @unlink($localPath);
            }
        }

        // IMP2-1.13 — stage the ZIP next to the data file (same tenant/session
        // prefix); the media handler downloads + extracts it after the row phase.
        if ($zipFile instanceof UploadedFile) {
            $zipRemotePath = \sprintf('%s/%s/%s', $tenant->getId()->toRfc4122(), $session->getId()->toRfc4122(), $zipName);
            try {
                $zipStream = fopen($zipFile->getPathname(), 'r');
                if (false === $zipStream) {
                    throw new RuntimeException('Failed to open uploaded ZIP for reading.');
                }
                $this->importsStorage->writeStream($zipRemotePath, $zipStream);
                if (\is_resource($zipStream)) {
                    fclose($zipStream);
                }
            } catch (FilesystemException|RuntimeException $exception) {
                $session->markFailed(\sprintf('Failed to stage uploaded ZIP: %s', $exception->getMessage()));
                $this->sessions->save($session);
                throw new BadRequestHttpException('Failed to stage the uploaded ZIP.', $exception);
            }
        }

        // Spec decision §3: <50 rows runs inline so the operator does not
        // wait on a worker; `total_rows` is unknown until the handler
        // streams the file, so we use the request-time threshold based on
        // `file_size_bytes` heuristic (small files always sync).
        if ($dataRowCount <= self::SYNC_THRESHOLD_ROWS) {
            $reload = $this->sessions->findById($session->getId());
            if ($reload instanceof ImportSession) {
                try {
                    $this->runHandler->run($reload);
                } catch (BulkOperationInProgressException $exception) {
                    // PROD-05 — translate the domain collision into a 409
                    // so the operator sees a clear conflict response. The
                    // ImportSession row stays `pending`; user can retry
                    // once the in-flight bulk job releases the lock.
                    throw new ConflictHttpException($exception->getMessage(), $exception);
                }
                $reload = $this->sessions->findById($session->getId()) ?? $reload;

                return new JsonResponse(
                    $this->serialise($reload),
                    Response::HTTP_OK,
                );
            }
        }

        $this->bus->dispatch(new ImportRunMessage(
            importSessionId: $session->getId(),
            tenantId: $tenant->getId(),
        ), [new TenantStamp($tenant->getId())]);

        return new JsonResponse($this->serialise($session), Response::HTTP_ACCEPTED);
    }

    /**
     * Resolve an optional staged_file_id to its owner-scoped {@see StagedFile},
     * or null when the field is absent (back-compat multipart path).
     */
    private function resolveStagedFile(Request $request, User $user): ?StagedFile
    {
        $raw = (string) $request->request->get('staged_file_id', '');
        if ('' === $raw) {
            return null;
        }
        try {
            $id = Uuid::fromString($raw);
        } catch (InvalidArgumentException) {
            throw new BadRequestHttpException(\sprintf('Invalid staged_file_id "%s".', $raw));
        }
        $staged = $this->stagedFiles->resolveOwned($id, $user->getTenant(), $user->getId());
        if (null === $staged) {
            throw new NotFoundHttpException(\sprintf('Staged file "%s" was not found.', $raw));
        }

        return $staged;
    }

    /**
     * IMP2-2.10 (#1486) — resolve the optional pre-import backup. Returns null
     * when `do_backup` is off; otherwise the referenced backup MUST exist, be
     * owned by the caller's tenant, and be `completed`.
     *
     * Surfaces: missing `backup_id` while requested → 422; unknown / foreign
     * tenant → 404 (no existence leak); not yet `completed` → 422 with the
     * current status. The FE already gates its CTA on `completed`, so a 422
     * here only fires on a tampered / out-of-band request.
     */
    private function resolveBackup(Request $request, Tenant $tenant): ?Backup
    {
        $rawFlag = strtolower((string) $request->request->get('do_backup', '0'));
        if (!\in_array($rawFlag, ['1', 'true', 'on', 'yes'], true)) {
            return null;
        }

        $rawId = (string) $request->request->get('backup_id', '');
        if ('' === $rawId) {
            throw new UnprocessableEntityHttpException(
                'Zaznaczono utworzenie backupu, ale nie przekazano backup_id ukończonego backupu.',
            );
        }
        try {
            $id = Uuid::fromString($rawId);
        } catch (InvalidArgumentException) {
            throw new UnprocessableEntityHttpException(\sprintf('Nieprawidłowy backup_id "%s".', $rawId));
        }

        $backup = $this->backups->findById($id);
        // 404 (not 403) for a foreign-tenant / unknown id — do not reveal that
        // a backup with this id exists for another tenant.
        if (!$backup instanceof Backup
            || $backup->getTenant()?->getId()->toRfc4122() !== $tenant->getId()->toRfc4122()) {
            throw new NotFoundHttpException(\sprintf('Backup "%s" nie został znaleziony.', $rawId));
        }
        // Every other path to a Backup gates on `backup:read` (GetBackupController
        // + BackupVoter). This endpoint is only guarded by `imports:run`, so a
        // same-tenant role with imports:run but no backup:read could otherwise
        // probe backup existence/status through the 422 below. Run the same voter
        // and 404 on denial to keep the no-leak contract. Catalog Manager — the
        // import persona — holds backup:read for exactly this (RbacMatrix), and
        // Owner/Admin bypass, so the wizard happy path is unaffected.
        if (!$this->security->isGranted('READ', $backup)) {
            throw new NotFoundHttpException(\sprintf('Backup "%s" nie został znaleziony.', $rawId));
        }
        if (BackupStatus::Completed !== $backup->getStatus()) {
            throw new UnprocessableEntityHttpException(\sprintf(
                'Backup "%s" nie jest ukończony (status: %s) — poczekaj na zakończenie snapshotu przed startem importu.',
                $rawId,
                $backup->getStatus()->value,
            ));
        }

        return $backup;
    }

    private function parseUuid(mixed $raw, string $field): Uuid
    {
        if (!\is_string($raw) || '' === $raw) {
            throw new BadRequestHttpException(\sprintf('"%s" is required.', $field));
        }
        try {
            return Uuid::fromString($raw);
        } catch (InvalidArgumentException) {
            throw new BadRequestHttpException(\sprintf('Invalid "%s" UUID "%s".', $field, $raw));
        }
    }

    /**
     * @return array<string, string>
     */
    private function parseMapping(mixed $raw): array
    {
        if (!\is_string($raw)) {
            throw new BadRequestHttpException('"mapping" must be a JSON string.');
        }
        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            throw new BadRequestHttpException('"mapping" must be a JSON object.');
        }

        $mapping = [];
        foreach ($decoded as $key => $value) {
            $mapping[(string) $key] = \is_string($value) ? $value : 'skip';
        }

        return $mapping;
    }

    /**
     * @param array<string, string> $mapping
     */
    private function resolveProfile(mixed $raw, Tenant $tenant, Uuid $userId, array $mapping, ObjectType $objectType): ?ImportProfile
    {
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }
        try {
            $profileId = Uuid::fromString($raw);
        } catch (InvalidArgumentException) {
            throw new BadRequestHttpException(\sprintf('Invalid "profile_id" UUID "%s".', $raw));
        }

        $profile = $this->profiles->findById($profileId);
        if (!$profile instanceof ImportProfile) {
            throw new NotFoundHttpException(\sprintf('ImportProfile "%s" was not found.', $raw));
        }
        if ($profile->getUserId()->toRfc4122() !== $userId->toRfc4122()) {
            throw new NotFoundHttpException(\sprintf('ImportProfile "%s" was not found.', $raw));
        }
        $profile->touchLastUsed();
        $this->profiles->save($profile);

        return $profile;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialise(ImportSession $session): array
    {
        return [
            'id' => $session->getId()->toRfc4122(),
            'status' => $session->getStatus()->value,
            'total_rows' => $session->getTotalRows(),
            'success_count' => $session->getSuccessCount(),
            'error_count' => $session->getErrorCount(),
            'updated_count' => $session->getUpdatedCount(),
            'skipped_count' => $session->getSkippedCount(),
            'mode' => $session->getMode()->value,
            'started_at' => $session->getStartedAt()?->format(DateTimeInterface::RFC3339_EXTENDED),
            'completed_at' => $session->getCompletedAt()?->format(DateTimeInterface::RFC3339_EXTENDED),
            'rollback_until' => $session->getRollbackUntil()?->format(DateTimeInterface::RFC3339_EXTENDED),
            'backup' => self::serializeBackup($session->getBackupSnapshot()),
        ];
    }

    /**
     * @return array{id: string, status: string, started_at: string}|null
     */
    private static function serializeBackup(?Backup $backup): ?array
    {
        if (!$backup instanceof Backup) {
            return null;
        }

        return [
            'id' => $backup->getId()->toRfc4122(),
            'status' => $backup->getStatus()->value,
            'started_at' => $backup->getStartedAt()->format(DateTimeInterface::RFC3339_EXTENDED),
        ];
    }

    private function resolveMode(mixed $raw, ?ImportProfile $profile): ImportMode
    {
        if (\is_string($raw) && '' !== $raw) {
            $mode = ImportMode::tryFrom(strtoupper($raw));
            if (!$mode instanceof ImportMode) {
                throw new BadRequestHttpException(\sprintf('Invalid "mode" "%s" — expected CREATE, UPDATE or UPSERT.', $raw));
            }

            return $mode;
        }

        return $profile?->getMode() ?? ImportMode::Upsert;
    }

    private function resolveCreateMissingOptions(mixed $raw, ?ImportProfile $profile): bool
    {
        if (\is_string($raw) && '' !== $raw) {
            return \in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
        }

        return $profile?->createMissingOptions() ?? false;
    }

    private function resolveMatchAttributeCode(mixed $raw, ?ImportProfile $profile, Tenant $tenant): ?string
    {
        $code = \is_string($raw) && '' !== trim($raw) ? trim($raw) : $profile?->getMatchAttributeCode();
        if (null === $code || '' === $code) {
            return null;
        }

        $attribute = $this->attributes->findByCode($code, $tenant);
        if (!$attribute instanceof Attribute) {
            throw new BadRequestHttpException(\sprintf('Unknown "match_attribute_code" "%s".', $code));
        }
        if (AttributeType::Identifier !== $attribute->getType()) {
            throw new BadRequestHttpException(\sprintf('"match_attribute_code" "%s" must be an identifier attribute, got "%s".', $code, $attribute->getType()->value));
        }

        return $code;
    }

    /**
     * Counts non-empty data lines (header excluded), stopping at $cap —
     * enough to answer "inline or worker?" without reading a huge file.
     */
    private function countDataRows(string $path, int $cap): int
    {
        $handle = @fopen($path, 'r');
        if (false === $handle) {
            return $cap;
        }
        $count = -1; // first line is the header
        try {
            while (false !== ($line = fgets($handle))) {
                if ('' === trim($line)) {
                    continue;
                }
                ++$count;
                if ($count >= $cap) {
                    return $count;
                }
            }
        } finally {
            fclose($handle);
        }

        return max(0, $count);
    }
}
