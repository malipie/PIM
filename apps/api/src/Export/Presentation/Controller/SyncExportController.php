<?php

declare(strict_types=1);

namespace App\Export\Presentation\Controller;

use App\Export\Application\Sync\SyncExportRunner;
use App\Export\Domain\Entity\ExportSession;
use App\Export\Domain\Enum\ExportEncoding;
use App\Export\Domain\Enum\ExportFormat;
use App\Export\Domain\Enum\ExportSource;
use App\Export\Domain\Enum\ExportTargetScope;
use App\Export\Domain\Message\RunExportMessage;
use App\Export\Domain\Repository\ExportSessionRepositoryInterface;
use App\Export\Presentation\Support\ExportEntityTypeResolver;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use App\Shared\Application\UserIdentityAware;
use DateTimeImmutable;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * EXP-05 (#584) — `POST /api/products/export`.
 *
 * Hybrid threshold contract (PRD §11.4):
 *   - target_count < {@see self::SYNC_THRESHOLD} → run synchronously,
 *     stream the produced XLSX / CSV back as the HTTP response body.
 *   - target_count ≥ threshold → create an `ExportSession` row in
 *     `status=pending`, return `202 Accepted` + `Location` pointing at
 *     the future detail endpoint (EXP-08). The async handler (EXP-06)
 *     picks up pending sessions and runs them.
 *
 * Hard cap {@see self::HARD_CAP} is enforced before any work — exports
 * larger than 500k SKU return RFC 7807 `Bad Request` with an explicit
 * suggestion to split the run.
 *
 * Validation lives inline (no Symfony Form / API Platform processor)
 * because the controller is the only entry point — keeps the failure
 * messages close to the contract and avoids a serializer round-trip
 * for a hot path.
 */
final class SyncExportController
{
    public const int SYNC_THRESHOLD = 100;
    public const int SOFT_CAP = 100_000;
    public const int HARD_CAP = 500_000;

    public function __construct(
        private readonly SyncExportRunner $runner,
        private readonly ExportSessionRepositoryInterface $sessions,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
        private readonly MessageBusInterface $bus,
        private readonly ExportEntityTypeResolver $entityTypeResolver,
    ) {
    }

    #[Route(
        path: '/api/products/export',
        name: 'pim_products_export',
        methods: ['POST'],
    )]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'run')]
    public function __invoke(Request $request): Response
    {
        $user = $this->security->getUser();
        if (!$user instanceof UserIdentityAware) {
            throw new AccessDeniedHttpException('Authenticated user identity required.');
        }
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new AccessDeniedHttpException('Tenant context required.');
        }

        $payload = $this->decodeJson($request);
        $selection = $this->entityTypeResolver->resolve($payload);
        $format = $this->parseFormat($payload);
        $targetScope = $this->parseScope($payload);
        $this->entityTypeResolver->assertScopeAllowed($selection->entityType, $targetScope);
        $encoding = $this->parseEncoding($payload, $format);
        // Structural exports may omit columns (the builder supplies its full
        // default set); catalog exports still require an explicit selection.
        $columns = $this->parseColumns($payload, allowEmpty: $selection->entityType->isStructural());
        $selectedIds = $this->parseSelectedIds($payload, $targetScope);
        $filterSnapshot = $this->parseFilterSnapshot($payload);
        $locales = $this->parseStringList($payload, 'locales');
        $channels = $this->parseStringList($payload, 'channels');
        $includeVariants = $payload['include_variants'] ?? true;
        if (!\is_bool($includeVariants)) {
            throw new BadRequestHttpException('include_variants must be boolean.');
        }

        // EXR-04 ships the model + API contract; the generation pipeline for
        // non-product types lands in EXR-05 (custom_module) / EXR-06
        // (structural). Reject execution of not-yet-runnable types with a
        // clear 422 rather than dispatching a run that would fail mid-stream.
        if (!$selection->entityType->isExecutable()) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Export of entity_type=%s is not implemented yet (delivered in EXR-05/EXR-06).',
                $selection->entityType->value,
            ));
        }

        $session = new ExportSession(
            userId: $user->getId(),
            source: $this->parseSource($payload),
            format: $format,
            targetScope: $targetScope,
            selectedColumns: $columns,
            encoding: $encoding,
            filterSnapshot: $filterSnapshot,
            selectedObjectIds: $selectedIds,
            locales: $locales,
            channels: $channels,
            includeVariants: $includeVariants,
            entityType: $selection->entityType,
            objectTypeId: $selection->objectTypeId,
        );
        $session->assignTenant($tenant);

        // Count targets so we know whether to go sync or async (structural
        // types count via their builder; catalog types via the resolved set).
        $targetCount = $this->runner->resolveTargetCount($session);
        $this->guardCaps($targetCount);
        $session->setTargetCount($targetCount);

        if ($targetCount >= self::SYNC_THRESHOLD) {
            // Async path: persist the pending session, dispatch the
            // RunExportMessage so EXP-06 handler picks it up (sync
            // transport in dev runs it inline; doctrine queue in prod).
            $this->sessions->save($session);
            $this->bus->dispatch(new RunExportMessage($session->getId(), $tenant->getId()));

            return new JsonResponse(
                data: [
                    'id' => $session->getId()->toRfc4122(),
                    'status' => $session->getStatus()->value,
                    'target_count' => $targetCount,
                ],
                status: Response::HTTP_ACCEPTED,
                headers: [
                    'Location' => sprintf('/api/exports/sessions/%s', $session->getId()->toRfc4122()),
                ],
            );
        }

        $tempPath = $this->prepareTempFile($format);
        $sessionId = $session->getId();
        try {
            $this->runner->runToFile($session, $tempPath);
        } catch (Throwable $error) {
            @unlink($tempPath);
            throw new HttpException(Response::HTTP_INTERNAL_SERVER_ERROR, $error->getMessage(), $error);
        }

        // IMP2-2.6 — the streaming runner clears the EM mid-run (flat memory),
        // detaching $session; reload it before the post-flight path flush.
        $session = $this->sessions->findById($sessionId) ?? $session;

        // EXR-12 — the sync session lands in history (screen 1 shows the
        // 1-second exports too), but its file is a temp deleted after send:
        // clear the dangling path so the UI never offers a dead download.
        $session->setFilePath(null);
        $this->sessions->save($session);

        $filename = $this->downloadFilename($format);

        $response = new BinaryFileResponse($tempPath);
        $response->headers->set('Content-Type', $this->contentTypeFor($format, $encoding));
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename,
        );
        $response->deleteFileAfterSend(true);

        return $response;
    }

    /**
     * EXR-14 — telemetry: the wizard reports its entry point. Only the two
     * UI-originated sources are accepted (saved_profile_run is minted by the
     * profile-run endpoint, never by this controller). Default keeps the
     * pre-EXR behavior for older clients.
     *
     * @param array<string, mixed> $payload
     */
    private function parseSource(array $payload): ExportSource
    {
        $value = $payload['source'] ?? null;
        if (null === $value) {
            return ExportSource::ListContext;
        }
        if (!\is_string($value)) {
            throw new BadRequestHttpException('source must be a string.');
        }
        $source = ExportSource::tryFrom($value);
        if (null === $source || ExportSource::SavedProfileRun === $source) {
            throw new BadRequestHttpException(sprintf('Unsupported source "%s".', $value));
        }

        return $source;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(Request $request): array
    {
        $body = $request->getContent();
        if ('' === $body) {
            return [];
        }
        $decoded = json_decode($body, true);
        if (!\is_array($decoded)) {
            throw new BadRequestHttpException('Request body must be a JSON object.');
        }
        $payload = [];
        foreach ($decoded as $key => $value) {
            if (!\is_string($key)) {
                throw new BadRequestHttpException('Request body must be a JSON object (string keys).');
            }
            $payload[$key] = $value;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function parseFormat(array $payload): ExportFormat
    {
        $value = $payload['format'] ?? null;
        if (!\is_string($value)) {
            throw new BadRequestHttpException('format is required (xlsx|csv|xml).');
        }
        $format = ExportFormat::tryFrom($value);
        if (null === $format) {
            throw new BadRequestHttpException(sprintf('Unsupported format "%s" — expected xlsx, csv or xml.', $value));
        }

        return $format;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function parseScope(array $payload): ExportTargetScope
    {
        $value = $payload['target_scope'] ?? null;
        if (!\is_string($value)) {
            throw new BadRequestHttpException('target_scope is required (selected|filter|all).');
        }
        $scope = ExportTargetScope::tryFrom($value);
        if (null === $scope) {
            throw new BadRequestHttpException(sprintf('Unsupported target_scope "%s".', $value));
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function parseEncoding(array $payload, ExportFormat $format): ?ExportEncoding
    {
        // Encoding is a CSV-only concern; XLSX and XML are always UTF-8.
        if (ExportFormat::Xlsx === $format || ExportFormat::Xml === $format) {
            return null;
        }
        $value = $payload['encoding'] ?? ExportEncoding::Utf8Bom->value;
        if (!\is_string($value)) {
            throw new BadRequestHttpException('encoding must be a string when format=csv.');
        }
        $encoding = ExportEncoding::tryFrom($value);
        if (null === $encoding) {
            throw new BadRequestHttpException(sprintf('Unsupported encoding "%s".', $value));
        }

        return $encoding;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function parseColumns(array $payload, bool $allowEmpty = false): array
    {
        $value = $payload['selected_columns'] ?? null;
        if ($allowEmpty && (null === $value || [] === $value)) {
            return [];
        }
        if (!\is_array($value) || [] === $value) {
            throw new BadRequestHttpException('selected_columns must be a non-empty array of column keys.');
        }
        $columns = [];
        foreach ($value as $entry) {
            if (!\is_string($entry) || '' === $entry) {
                throw new BadRequestHttpException('selected_columns entries must be non-empty strings.');
            }
            $columns[] = $entry;
        }

        return $columns;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>|null
     */
    private function parseSelectedIds(array $payload, ExportTargetScope $scope): ?array
    {
        if (ExportTargetScope::Selected !== $scope) {
            return null;
        }
        $value = $payload['selected_object_ids'] ?? null;
        if (!\is_array($value) || [] === $value) {
            throw new BadRequestHttpException('selected_object_ids is required when target_scope=selected.');
        }
        $ids = [];
        foreach ($value as $id) {
            if (!\is_string($id) || !Uuid::isValid($id)) {
                throw new BadRequestHttpException('selected_object_ids must contain RFC 4122 UUID strings.');
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>|null
     */
    private function parseStringList(array $payload, string $key): ?array
    {
        $value = $payload[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_array($value)) {
            throw new BadRequestHttpException(sprintf('%s must be an array or omitted.', $key));
        }
        $entries = [];
        foreach ($value as $entry) {
            if (!\is_string($entry) || '' === $entry) {
                throw new BadRequestHttpException(sprintf('%s entries must be non-empty strings.', $key));
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|null
     */
    private function parseFilterSnapshot(array $payload): ?array
    {
        $value = $payload['filter_snapshot'] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_array($value)) {
            throw new BadRequestHttpException('filter_snapshot must be a JSON object or null.');
        }
        $result = [];
        foreach ($value as $key => $val) {
            if (!\is_string($key)) {
                throw new BadRequestHttpException('filter_snapshot must be a JSON object (string keys).');
            }
            $result[$key] = $val;
        }

        return $result;
    }

    private function guardCaps(int $targetCount): void
    {
        if ($targetCount > self::HARD_CAP) {
            throw new BadRequestHttpException(sprintf(
                'Export target count (%d) exceeds hard cap of %d. Split into multiple exports.',
                $targetCount,
                self::HARD_CAP,
            ));
        }
    }

    private function prepareTempFile(ExportFormat $format): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pim-export-');
        if (false === $tmp) {
            throw new HttpException(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'Unable to allocate temp file for export.',
            );
        }
        // Append format-correct extension so OpenSpout writes a valid
        // archive header (XLSX is detected by extension internally).
        $withExt = $tmp.'.'.$format->value;
        if (!@rename($tmp, $withExt)) {
            return $tmp;
        }

        return $withExt;
    }

    private function downloadFilename(ExportFormat $format): string
    {
        return sprintf('pim-export-%s.%s', new DateTimeImmutable()->format('Ymd-His'), $format->value);
    }

    private function contentTypeFor(ExportFormat $format, ?ExportEncoding $encoding): string
    {
        return match ($format) {
            ExportFormat::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ExportFormat::Xml => 'application/xml; charset=utf-8',
            ExportFormat::Csv => sprintf(
                'text/csv; charset=%s',
                ExportEncoding::Windows1250 === $encoding ? 'windows-1250' : 'utf-8',
            ),
        };
    }
}
