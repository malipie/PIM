<?php

declare(strict_types=1);

namespace App\Import\Presentation\Controller;

use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Contracts\Auth\CurrentUserProvider;
use App\Import\Application\Handler\StructuralImportRunHandler;
use App\Import\Application\Service\Archive\ArchiveSecurityException;
use App\Import\Application\Service\Archive\XlsxArchiveGuard;
use App\Import\Application\Service\StagedFileService;
use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Entity\StagedFile;
use App\Import\Domain\Repository\ImportSessionRepositoryInterface;
use App\Shared\Application\BulkOperationInProgressException;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use DateTimeInterface;
use InvalidArgumentException;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use RuntimeException;
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
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Start a structural import (attribute / attribute-group definitions) — the
 * mirror of the `attributes_groups` / `attribute_groups` exports.
 *
 * Unlike {@see StartImportController} this carries no `target_object_type_id`
 * (the rows create configuration entities, not CatalogObjects) and requires a
 * `structural_kind` of `attributes` | `attribute_groups`. Small files (< 50
 * data rows) run inline; larger ones hand off to the worker via
 * {@see StructuralImportRunMessage}.
 *
 * Multipart fields: `structural_kind`, plus either `staged_file_id` (uploaded
 * once at parse-preview) or a fresh `file`.
 */
final class StartStructuralImportController
{
    private const int DEFAULT_MAX_ROWS = 200_000;
    private const int DEFAULT_MAX_FILE_BYTES = 100 * 1024 * 1024;

    private const array ALLOWED_KINDS = ['attributes', 'attribute_groups'];

    public function __construct(
        private readonly ImportSessionRepositoryInterface $sessions,
        private readonly StructuralImportRunHandler $runHandler,
        private readonly CurrentUserProvider $currentUser,
        private readonly TenantContext $tenantContext,
        private readonly FilesystemOperator $importsStorage,
        private readonly StagedFileService $stagedFiles,
        private readonly RateLimiterFactoryInterface $importTriggerLimiter,
        private readonly XlsxArchiveGuard $xlsxArchiveGuard,
    ) {
    }

    #[Route(path: '/api/structural-import-sessions', name: 'imports_structural_start', methods: ['POST'])]
    #[RequiresPermission(module: 'imports', action: 'run')]
    public function __invoke(Request $request): JsonResponse
    {
        $userId = $this->currentUser->userId();
        if (null === $userId) {
            throw new UnauthorizedHttpException('JWT', 'Authenticated user required.');
        }
        $tenant = $this->currentUser->tenant();
        if (null === $tenant) {
            throw new UnauthorizedHttpException('JWT', 'Authenticated user required.');
        }
        $this->tenantContext->set($tenant);

        $reservation = $this->importTriggerLimiter->create($tenant->getId()->toRfc4122())->consume();
        if (!$reservation->isAccepted()) {
            throw new TooManyRequestsHttpException(
                max(0, $reservation->getRetryAfter()->getTimestamp() - time()),
                'Przekroczono limit importów dla tego tenanta. Spróbuj ponownie później.',
            );
        }

        $kind = (string) $request->request->get('structural_kind', '');
        if (!\in_array($kind, self::ALLOWED_KINDS, true)) {
            throw new BadRequestHttpException(\sprintf('"structural_kind" must be one of: %s.', implode(', ', self::ALLOWED_KINDS)));
        }

        $stagedFile = $this->resolveStagedFile($request, $tenant, $userId);
        $file = $request->files->get('file');
        if (null === $stagedFile && !$file instanceof UploadedFile) {
            throw new BadRequestHttpException('Either "staged_file_id" or a "file" multipart field is required.');
        }
        $localPath = null !== $stagedFile ? $this->stagedFiles->downloadToTemp($stagedFile) : $file->getPathname();
        $originalName = null !== $stagedFile ? $stagedFile->getFileName() : $file->getClientOriginalName();
        if ('' === $originalName) {
            $originalName = 'upload.csv';
        }
        $fileSizeBytes = null !== $stagedFile ? $stagedFile->getSizeBytes() : (int) $file->getSize();

        if ($fileSizeBytes > self::DEFAULT_MAX_FILE_BYTES) {
            throw new UnprocessableEntityHttpException(\sprintf(
                'Plik (%d B) przekracza limit %d MB.',
                $fileSizeBytes,
                intdiv(self::DEFAULT_MAX_FILE_BYTES, 1024 * 1024),
            ));
        }

        if (str_ends_with(strtolower($originalName), '.xlsx')) {
            try {
                $this->xlsxArchiveGuard->validate($localPath);
            } catch (ArchiveSecurityException $exception) {
                throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
            }
        }

        // Structural imports operate on configuration entities (attribute /
        // attribute-group definitions), which are bounded-small by nature, so
        // they always run inline — never through the Messenger worker. A CSV
        // row count still guards against an absurdly large upload.
        if (str_ends_with(strtolower($originalName), '.csv')
            && $this->countDataRows($localPath, self::DEFAULT_MAX_ROWS + 1) > self::DEFAULT_MAX_ROWS) {
            throw new UnprocessableEntityHttpException(\sprintf('Plik przekracza limit %d wierszy.', self::DEFAULT_MAX_ROWS));
        }

        $session = new ImportSession(
            userId: $userId,
            targetObjectType: null,
            fileName: $originalName,
            fileSizeBytes: $fileSizeBytes,
            structuralKind: $kind,
        );
        $this->sessions->save($session);

        $remotePath = \sprintf('%s/%s/%s', $tenant->getId()->toRfc4122(), $session->getId()->toRfc4122(), $session->getFileName());
        try {
            if (null !== $stagedFile) {
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
            if (null !== $stagedFile) {
                @unlink($localPath);
            }
        }

        $reload = $this->sessions->findById($session->getId()) ?? $session;
        try {
            $this->runHandler->run($reload);
        } catch (BulkOperationInProgressException $exception) {
            // PROD-05 — another bulk job holds the per-tenant lock. The session
            // stays `pending`; the operator can retry once it releases.
            throw new ConflictHttpException($exception->getMessage(), $exception);
        }
        $reload = $this->sessions->findById($session->getId()) ?? $reload;

        return new JsonResponse($this->serialise($reload), Response::HTTP_OK);
    }

    private function resolveStagedFile(Request $request, Tenant $tenant, Uuid $userId): ?StagedFile
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
        $staged = $this->stagedFiles->resolveOwned($id, $tenant, $userId);
        if (null === $staged) {
            throw new NotFoundHttpException(\sprintf('Staged file "%s" was not found.', $raw));
        }

        return $staged;
    }

    private function countDataRows(string $path, int $cap): int
    {
        $handle = @fopen($path, 'r');
        if (false === $handle) {
            return $cap;
        }
        $count = -1; // header line
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

    /**
     * @return array<string, mixed>
     */
    private function serialise(ImportSession $session): array
    {
        return [
            'id' => $session->getId()->toRfc4122(),
            'status' => $session->getStatus()->value,
            'structural_kind' => $session->getStructuralKind(),
            'total_rows' => $session->getTotalRows(),
            'success_count' => $session->getSuccessCount(),
            'error_count' => $session->getErrorCount(),
            'updated_count' => $session->getUpdatedCount(),
            'skipped_count' => $session->getSkippedCount(),
            'started_at' => $session->getStartedAt()?->format(DateTimeInterface::RFC3339_EXTENDED),
            'completed_at' => $session->getCompletedAt()?->format(DateTimeInterface::RFC3339_EXTENDED),
        ];
    }
}
