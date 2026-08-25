<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Domain\Entity\StagedFile;
use App\Import\Domain\Repository\StagedFileRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToCopyFile;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Throwable;

use const PATHINFO_EXTENSION;

/**
 * IMP2-2.2 — stages a wizard upload once and hands back a {@see StagedFile}
 * the dry-run + start steps reuse by id, instead of re-uploading the bytes.
 *
 * Bytes land on the `imports.storage` disk under
 * `{tenant}/staged/{stagedFileId}/{filename}`; the row carries ownership +
 * TTL metadata. Resolution is owner-scoped — a staged_file_id only resolves
 * for the tenant + user that created it.
 */
final readonly class StagedFileService
{
    public function __construct(
        private StagedFileRepositoryInterface $repository,
        private FilesystemOperator $importsStorage,
        private TenantContext $tenantContext,
    ) {
    }

    /**
     * Upload the local file to the staged area and persist its handle.
     *
     * @param string $localPath    readable path to the uploaded bytes
     * @param string $originalName client filename (kept for display + extension)
     */
    public function stage(string $localPath, string $originalName, int $sizeBytes, Tenant $tenant, Uuid $userId): StagedFile
    {
        // The TenantAssignmentListener stamps tenant_id on prePersist from the
        // active context; parse-preview is otherwise read-only and never set it.
        $this->tenantContext->set($tenant);

        $id = Uuid::v7();
        $storageKey = \sprintf(
            '%s/staged/%s/%s',
            $tenant->getId()->toRfc4122(),
            $id->toRfc4122(),
            $originalName,
        );

        $stream = fopen($localPath, 'r');
        if (false === $stream) {
            throw new RuntimeException('Failed to open the uploaded file for staging.');
        }
        try {
            $this->importsStorage->writeStream($storageKey, $stream);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        $stagedFile = new StagedFile($userId, $originalName, $sizeBytes, $storageKey, $id);
        $this->repository->save($stagedFile);

        return $stagedFile;
    }

    /**
     * Resolve a staged file owned by this tenant + user, or null when it does
     * not exist / belongs to someone else (caller turns null into a 404).
     */
    public function resolveOwned(Uuid $id, Tenant $tenant, Uuid $userId): ?StagedFile
    {
        return $this->repository->findOwned($id, $tenant, $userId);
    }

    /**
     * Materialise the staged bytes to a local temp file whose name keeps the
     * original extension, so the extension-dispatching reader/parser work
     * unchanged. The caller owns the returned path and must unlink it.
     */
    public function downloadToTemp(StagedFile $stagedFile): string
    {
        // basename() before pathinfo() as defence in depth — the extension only
        // ever names the temp suffix, and getClientOriginalName()/Flysystem
        // already strip path components, but this makes traversal impossible.
        $extension = strtolower(pathinfo(basename($stagedFile->getFileName()), PATHINFO_EXTENSION));
        $tempPath = tempnam(sys_get_temp_dir(), 'pim_staged_');
        if (false === $tempPath) {
            throw new RuntimeException('Failed to allocate a temp file for the staged upload.');
        }
        $finalPath = '' === $extension ? $tempPath : $tempPath.'.'.$extension;
        if ($finalPath !== $tempPath && !@rename($tempPath, $finalPath)) {
            @unlink($tempPath);
            throw new RuntimeException('Failed to prepare a temp file for the staged upload.');
        }

        try {
            $read = $this->importsStorage->readStream($stagedFile->getStorageKey());
        } catch (FilesystemException $exception) {
            @unlink($finalPath);
            throw new RuntimeException('Staged file is no longer available in storage.', 0, $exception);
        }
        // $read is open from here — the outer finally guarantees it is closed on
        // every exit, including when fopen() below fails.
        try {
            $write = fopen($finalPath, 'w');
            if (false === $write) {
                throw new RuntimeException('Failed to open a temp file for the staged upload.');
            }
            try {
                stream_copy_to_stream($read, $write);
            } finally {
                if (\is_resource($write)) {
                    fclose($write);
                }
            }
        } catch (Throwable $throwable) {
            @unlink($finalPath);

            throw $throwable;
        } finally {
            if (\is_resource($read)) {
                fclose($read);
            }
        }

        return $finalPath;
    }

    /**
     * Server-side copy of the staged bytes to the import session's own key
     * (`{tenant}/{session}/{file}`), so the worker reads them where it expects
     * without the client re-uploading. Returns false if the copy fails.
     */
    public function copyToKey(StagedFile $stagedFile, string $destinationKey): void
    {
        try {
            $this->importsStorage->copy($stagedFile->getStorageKey(), $destinationKey);

            return;
        } catch (UnableToCopyFile) {
            // Some S3-compatible stores (including the production MinIO
            // setup) accept ordinary reads/writes but reject CopyObject. The
            // staged file is known to be readable because parse-preview and
            // dry-run already consumed it, so fall back to a streamed copy
            // instead of failing the final import step with HTTP 400.
        }

        $read = $this->importsStorage->readStream($stagedFile->getStorageKey());
        try {
            $this->importsStorage->writeStream($destinationKey, $read);
        } finally {
            if (\is_resource($read)) {
                fclose($read);
            }
        }
    }
}
