<?php

declare(strict_types=1);

namespace App\Import\Presentation\Controller;

use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Entity\User;
use App\Import\Application\Service\ImportValidationService;
use App\Import\Application\Service\StagedFileService;
use App\Import\Domain\Enum\FileEncoding;
use App\Import\Domain\Exception\InvalidImportFileException;
use App\Import\Domain\ValueObject\ValidationError;
use App\Import\Domain\ValueObject\ValidationResult;
use InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * IMP-03 (#444) wizard Step 3 — uploads the file again as multipart,
 * runs the validation pass without committing anything, and returns
 * `{success_count, error_count, errors[]}` for the preview UI.
 *
 * No `import_session_id` is created here — the dry run is stateless
 * by design (spec §5.4: "wynik dry-run"). Step 4 confirm posts the
 * mapping again to the persisting endpoint that lands in IMP-04.
 */
final class ValidateDryRunController
{
    /**
     * How many rows one dry-run inspects (#2810).
     *
     * The wizard step is interactive, so this is bounded by what an operator
     * will wait for, not by file size. Scanning a 51 800-row export took over
     * 30 s and returned a PHP fatal wrapped in HTTP 200 — the browser saw a
     * success code carrying an HTML error page. Mapping and format mistakes
     * surface in the first rows; the full pass belongs to the async run,
     * which validates every row and reports real totals.
     */
    private const int DRY_RUN_MAX_ROWS = 1_000;

    private const int MAX_TOP_ERRORS = 10;

    public function __construct(
        private readonly ImportValidationService $validator,
        private readonly ObjectTypeRepositoryInterface $objectTypes,
        private readonly StagedFileService $stagedFiles,
        private readonly Security $security,
    ) {
    }

    #[Route(
        path: '/api/import-sessions/validate-dry-run',
        name: 'imports_validate_dry_run',
        methods: ['POST'],
    )]
    #[RequiresPermission(module: 'imports', action: 'run')]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('JWT', 'Authenticated user required.');
        }

        $rawTargetId = (string) $request->request->get('target_object_type_id', '');
        if ('' === $rawTargetId) {
            throw new BadRequestHttpException('"target_object_type_id" field is required.');
        }
        try {
            $targetId = Uuid::fromString($rawTargetId);
        } catch (InvalidArgumentException) {
            throw new BadRequestHttpException(\sprintf('Invalid target_object_type_id "%s".', $rawTargetId));
        }

        $objectType = $this->objectTypes->findById($targetId);
        if (!$objectType instanceof ObjectType) {
            throw new NotFoundHttpException(\sprintf('ObjectType "%s" was not found.', $rawTargetId));
        }

        $rawMapping = (string) $request->request->get('mapping', '{}');
        $decoded = json_decode($rawMapping, true);
        if (!\is_array($decoded)) {
            throw new BadRequestHttpException('"mapping" must be a JSON object {column_header: attribute_code}.');
        }

        $columnMapping = [];
        foreach ($decoded as $key => $value) {
            $columnMapping[(string) $key] = \is_string($value) ? $value : 'skip';
        }

        $encoding = $this->parseEncoding($request->request->get('encoding'));
        $delimiter = $this->parseDelimiter($request->request->get('delimiter'));

        // IMP2-2.2 — prefer a staged_file_id (single upload reused across
        // steps); fall back to a fresh multipart upload for back-compat.
        $finalPath = $this->resolveSourcePath($request, $user);

        try {
            $result = $this->validator->validate(
                absolutePath: $finalPath,
                columnMapping: $columnMapping,
                target: $objectType,
                encodingOverride: $encoding,
                delimiterOverride: $delimiter,
                maxRows: self::DRY_RUN_MAX_ROWS,
            );
        } catch (InvalidImportFileException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        } finally {
            @unlink($finalPath);
        }

        return new JsonResponse($this->serialise($result), Response::HTTP_OK);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialise(ValidationResult $result): array
    {
        return [
            'total_rows' => $result->totalRows,
            'truncated' => $result->truncated,
            'success_count' => $result->successCount,
            'error_count' => $result->errorCount,
            'top_errors' => array_map(
                static fn (ValidationError $error): array => [
                    'row_number' => $error->rowNumber,
                    'sku' => $error->sku,
                    'error_type' => $error->errorType->value,
                    'level' => $error->level->value,
                    'message' => $error->message,
                    'column_name' => $error->columnName,
                    'column_value' => $error->columnValue,
                ],
                \array_slice($result->errors, 0, self::MAX_TOP_ERRORS),
            ),
            'all_errors' => array_map(
                static fn (ValidationError $error): array => [
                    'row_number' => $error->rowNumber,
                    'sku' => $error->sku,
                    'error_type' => $error->errorType->value,
                    'level' => $error->level->value,
                    'message' => $error->message,
                    'column_name' => $error->columnName,
                    'column_value' => $error->columnValue,
                ],
                $result->errors,
            ),
        ];
    }

    /**
     * Returns a local temp path for the source file (caller unlinks it),
     * resolved from either a staged_file_id or a fresh multipart upload.
     */
    private function resolveSourcePath(Request $request, User $user): string
    {
        $rawStagedId = (string) $request->request->get('staged_file_id', '');
        if ('' !== $rawStagedId) {
            try {
                $stagedId = Uuid::fromString($rawStagedId);
            } catch (InvalidArgumentException) {
                throw new BadRequestHttpException(\sprintf('Invalid staged_file_id "%s".', $rawStagedId));
            }
            $staged = $this->stagedFiles->resolveOwned($stagedId, $user->getTenant(), $user->getId());
            if (null === $staged) {
                throw new NotFoundHttpException(\sprintf('Staged file "%s" was not found.', $rawStagedId));
            }

            return $this->stagedFiles->downloadToTemp($staged);
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new BadRequestHttpException('Either "staged_file_id" or a "file" multipart field is required.');
        }

        // ImportRowReader dispatches by extension via pathinfo(), but PHP writes
        // multipart uploads to /tmp/phpXXXX with no extension. Copy to a temp
        // path that preserves the client's original extension before validating.
        $extension = strtolower($file->getClientOriginalExtension());
        if ('' === $extension) {
            throw new BadRequestHttpException('Uploaded file must have an extension (.csv or .xlsx).');
        }
        $tempPath = tempnam(sys_get_temp_dir(), 'pim_dry_run_');
        if (false === $tempPath) {
            throw new BadRequestHttpException('Failed to allocate a temp file for validation.');
        }
        $finalPath = $tempPath.'.'.$extension;
        if (!@rename($tempPath, $finalPath)) {
            @unlink($tempPath);
            throw new BadRequestHttpException('Failed to prepare a temp file for validation.');
        }
        if (!@copy($file->getPathname(), $finalPath)) {
            @unlink($finalPath);
            throw new BadRequestHttpException('Failed to copy uploaded file for validation.');
        }

        return $finalPath;
    }

    private function parseEncoding(mixed $raw): ?FileEncoding
    {
        if (!\is_string($raw) || '' === $raw || 'auto' === $raw) {
            return null;
        }

        return FileEncoding::tryFrom($raw);
    }

    private function parseDelimiter(mixed $raw): ?string
    {
        if (!\is_string($raw) || '' === $raw || 'auto' === $raw) {
            return null;
        }
        if ('tab' === $raw) {
            return "\t";
        }

        return $raw;
    }
}
