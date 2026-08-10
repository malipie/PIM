<?php

declare(strict_types=1);

namespace App\Import\Presentation\Controller;

use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Contracts\Auth\CurrentUserProvider;
use App\Import\Application\Service\Archive\ArchiveSecurityException;
use App\Import\Application\Service\Archive\XlsxArchiveGuard;
use App\Import\Application\Service\FileParserService;
use App\Import\Application\Service\StagedFileService;
use App\Import\Domain\Enum\FileEncoding;
use App\Import\Domain\Exception\InvalidImportFileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Wizard Step 1 → Step 2 transition: parses the just-uploaded source
 * file with the same {@see FileParserService} the start/validate
 * pipeline uses, returning headers + sample rows so the mapping table
 * stays authoritative for both CSV and xlsx. Without this, the admin
 * had a CSV-only in-browser parser and an xlsx sentinel that left the
 * Mapping step with a single "__xlsx__" row.
 *
 * IMP2-2.2 — besides parsing, it stages the upload once (MinIO) and
 * returns a `staged_file_id` the dry-run + start steps reuse, so the wizard
 * sends the bytes exactly once instead of three times.
 */
final class ParsePreviewController
{
    /** IMP2-2.7 (#1483) — D10 application defaults when a tenant sets no override. */
    private const int DEFAULT_MAX_ROWS = 200_000;
    private const int DEFAULT_MAX_FILE_BYTES = 100 * 1024 * 1024;

    public function __construct(
        private readonly FileParserService $parser,
        private readonly StagedFileService $stagedFiles,
        private readonly CurrentUserProvider $currentUser,
        private readonly XlsxArchiveGuard $xlsxArchiveGuard,
    ) {
    }

    #[Route(
        path: '/api/import-sessions/parse-preview',
        name: 'imports_parse_preview',
        methods: ['POST'],
    )]
    #[RequiresPermission(module: 'imports', action: 'run')]
    public function __invoke(Request $request): JsonResponse
    {
        // #2808 — defence for workbooks that declare no usable dimension: the
        // parser then falls back to counting by iteration, and PHP's 30 s
        // default turns a slow-but-legitimate file into a fatal error, which
        // escapes as an HTML 500 instead of Problem Details. Bulk handlers
        // lift the limit for the same reason; the one endpoint that accepts
        // arbitrary user files had no such guard at all.
        set_time_limit(120);

        $userId = $this->currentUser->userId();
        if (null === $userId) {
            throw new UnauthorizedHttpException('JWT', 'Authenticated user required.');
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new BadRequestHttpException('"file" multipart field is required.');
        }

        // IMP2-2.7 (#1483) — file-size guardrail before we copy/parse anything,
        // so an oversized upload gets a clear 422 in the wizard preview.
        $tenant = $this->currentUser->tenant();
        if (null === $tenant) {
            throw new UnauthorizedHttpException('JWT', 'Authenticated user required.');
        }
        $maxFileBytes = $tenant->getImportMaxFileSize() ?? self::DEFAULT_MAX_FILE_BYTES;
        if ((int) $file->getSize() > $maxFileBytes) {
            throw new UnprocessableEntityHttpException(\sprintf(
                'Plik (%d B) przekracza limit %d MB.',
                (int) $file->getSize(),
                intdiv($maxFileBytes, 1024 * 1024),
            ));
        }

        $encodingOverride = $this->parseEncoding($request->request->get('encoding'));
        $delimiterOverride = $this->parseDelimiter($request->request->get('delimiter'));

        // FileParserService keys off the extension via pathinfo() — the
        // PHP temp upload has a synthetic name (php2A.tmp), so copy to a
        // temp path that preserves the client's original extension before
        // delegating.
        $extension = strtolower($file->getClientOriginalExtension());
        if ('' === $extension) {
            throw new BadRequestHttpException('Uploaded file must have an extension (.csv or .xlsx).');
        }
        $tempPath = tempnam(sys_get_temp_dir(), 'pim_parse_');
        if (false === $tempPath) {
            throw new BadRequestHttpException('Failed to allocate a temp file for parsing.');
        }
        $finalPath = $tempPath.'.'.$extension;
        if (!@rename($tempPath, $finalPath)) {
            @unlink($tempPath);
            throw new BadRequestHttpException('Failed to prepare a temp file for parsing.');
        }
        if (!@copy($file->getPathname(), $finalPath)) {
            @unlink($finalPath);
            throw new BadRequestHttpException('Failed to copy uploaded file for parsing.');
        }

        // IMP2-2.8 (#1484) — zip-bomb guard before parsing any XLSX (a crafted
        // archive can OOM the worker during parse). Reject as RFC 7807 422.
        if ('xlsx' === $extension) {
            try {
                $this->xlsxArchiveGuard->validate($finalPath);
            } catch (ArchiveSecurityException $exception) {
                @unlink($finalPath);
                throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
            }
        }

        try {
            $parsed = $this->parser->parse($finalPath, $encodingOverride, $delimiterOverride);
        } catch (InvalidImportFileException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        } finally {
            @unlink($finalPath);
        }

        // IMP2-2.7 (#1483) — row-count guardrail. The parser already streamed the
        // file to produce totalRows (CSV + XLSX), so this catches both formats
        // here, before the user proceeds to mapping/start.
        $maxRows = $tenant->getImportMaxRows() ?? self::DEFAULT_MAX_ROWS;
        if ($parsed->totalRows > $maxRows) {
            throw new UnprocessableEntityHttpException(\sprintf(
                'Plik ma %d wierszy — przekracza limit %d.',
                $parsed->totalRows,
                $maxRows,
            ));
        }

        // Stage the (valid) upload once so the dry-run + start steps reuse it
        // by id. Original name carries the extension the staged reader needs.
        $originalName = $file->getClientOriginalName();
        if ('' === $originalName) {
            $originalName = 'upload.'.$extension;
        }
        $staged = $this->stagedFiles->stage(
            $file->getPathname(),
            $originalName,
            (int) $file->getSize(),
            $tenant,
            $userId,
        );

        return new JsonResponse(
            [
                'staged_file_id' => $staged->getId()->toRfc4122(),
                'headers' => $parsed->headers,
                'sample_rows' => $parsed->sampleRows,
                'total_rows' => $parsed->totalRows,
                'encoding' => $parsed->encoding->value,
                'delimiter' => $parsed->delimiter,
                'sheet_name' => $parsed->sheetName,
                'had_multiple_sheets' => $parsed->hadMultipleSheets,
            ],
            Response::HTTP_OK,
        );
    }

    private function parseEncoding(mixed $raw): ?FileEncoding
    {
        if (!\is_string($raw) || '' === $raw || 'auto' === $raw) {
            return null;
        }

        return FileEncoding::tryFrom($raw)
            ?? throw new BadRequestHttpException(\sprintf('Unsupported encoding "%s".', $raw));
    }

    private function parseDelimiter(mixed $raw): ?string
    {
        if (!\is_string($raw) || '' === $raw || 'auto' === $raw) {
            return null;
        }
        if ('tab' === $raw) {
            return "\t";
        }
        // Single-character delimiters only — CSV reader rejects multi-char.
        if (1 !== \strlen($raw)) {
            throw new BadRequestHttpException(\sprintf('Delimiter must be a single character or "tab", got "%s".', $raw));
        }

        return $raw;
    }
}
