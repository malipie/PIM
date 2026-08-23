<?php

declare(strict_types=1);

namespace App\Export\Presentation\Controller;

use App\Channel\Contracts\ChannelResolverInterface;
use App\Export\Application\Builder\ColumnResolver;
use App\Export\Application\Builder\Structural\StructuralExportBuilderInterface;
use App\Export\Application\Sync\SyncExportRunner;
use App\Export\Domain\Entity\ExportSession;
use App\Export\Domain\Enum\ExportEntityType;
use App\Export\Domain\Enum\ExportFormat;
use App\Export\Domain\Enum\ExportSource;
use App\Export\Domain\Enum\ExportTargetScope;
use App\Export\Presentation\Support\ExportEntityTypeResolver;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * EXR-07 (#1383) — preflight count + sync/async routing contract.
 *
 * The wizard needs an exact "how many rows will this export?" probe before
 * running, and the resulting sync-vs-async decision (so Krok 2/4 can show the
 * live counter + asynchronicity note). For catalog objects this endpoint
 * resolves the same immutable id plan as the runner — no side effects — and
 * uses the same {@see SyncExportController::SYNC_THRESHOLD} constant the
 * runner routes on (single source of truth; the UI never hardcodes 100).
 *
 * Scope (EXR-07): `product` and `custom_module`. Structural entity types
 * always export the full configuration set; their preflight count lands with
 * the structural builders in EXR-06.
 */
final class ExportPreflightController
{
    public function __construct(
        private readonly ExportEntityTypeResolver $entityTypeResolver,
        private readonly TenantContext $tenantContext,
        private readonly ColumnResolver $columnResolver,
        private readonly ChannelResolverInterface $channelResolver,
        private readonly SyncExportRunner $runner,
        /** @var iterable<StructuralExportBuilderInterface> */
        #[AutowireIterator('app.export.structural_builder')]
        private readonly iterable $structuralBuilders = [],
    ) {
    }

    #[Route(
        path: '/api/exports/preflight',
        name: 'pim_export_preflight',
        methods: ['POST'],
    )]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'run')]
    public function __invoke(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new AccessDeniedHttpException('Tenant context required.');
        }

        $payload = $this->decodeJson($request);
        $selection = $this->entityTypeResolver->resolve($payload);
        $scope = $this->parseScope($payload);
        $this->entityTypeResolver->assertScopeAllowed($selection->entityType, $scope);

        // IMP2-1.6 (R-47): reject a channel-scoped column whose channel no
        // longer exists BEFORE the export runs, so a stale profile cannot
        // produce an empty column that clear_if_empty then wipes downstream.
        $this->assertChannelColumnsResolvable($payload, $tenant);

        if ($selection->entityType->isStructural()) {
            // EXR-06: structural exports always run target_scope=all; the row
            // count comes from the matching structural builder.
            $count = $this->structuralCount($selection->entityType, $tenant);
        } else {
            // #2987 — materialise the same immutable scope contract the run
            // endpoint stores. SyncExportRunner delegates to ExportScopeResolver
            // for both this count and the final file id plan.
            $session = new ExportSession(
                userId: Uuid::v7(),
                source: ExportSource::CentralTab,
                format: ExportFormat::Csv,
                targetScope: $scope,
                selectedColumns: [],
                filterSnapshot: $this->parseFilter($payload),
                selectedObjectIds: $this->parseSelectedIds($payload, $scope),
                includeVariants: $this->parseIncludeVariants($payload),
                entityType: $selection->entityType,
                objectTypeId: $selection->objectTypeId,
            );
            $session->assignTenant($tenant);
            $count = $this->runner->resolveTargetCount($session);
        }

        $mode = $count >= SyncExportController::SYNC_THRESHOLD ? 'async' : 'sync';

        return new JsonResponse([
            'count' => $count,
            'mode' => $mode,
            'threshold' => SyncExportController::SYNC_THRESHOLD,
            'soft_cap' => SyncExportController::SOFT_CAP,
            'exceeds_cap' => $count > SyncExportController::SOFT_CAP,
        ]);
    }

    /**
     * IMP2-1.6 (#1469, R-47): 422 when a `selected_columns` entry is a
     * channel-scoped column (`price.shopify`, `name.pl.shopify`) whose
     * channel does not resolve in the tenant. Columns/channels are optional
     * in the payload — when absent the preflight stays a pure count.
     *
     * @param array<string, mixed> $payload
     */
    private function assertChannelColumnsResolvable(array $payload, Tenant $tenant): void
    {
        $rawColumns = $payload['selected_columns'] ?? null;
        if (!\is_array($rawColumns) || [] === $rawColumns) {
            return;
        }

        $columnKeys = [];
        foreach ($rawColumns as $key) {
            if (\is_string($key)) {
                $columnKeys[] = $key;
            }
        }

        $channelCodes = [];
        $rawChannels = $payload['channels'] ?? null;
        if (\is_array($rawChannels)) {
            foreach ($rawChannels as $code) {
                if (\is_string($code)) {
                    $channelCodes[] = $code;
                }
            }
        }

        $referenced = [];
        foreach ($this->columnResolver->resolve($columnKeys, $channelCodes) as $column) {
            if (null !== $column->channel) {
                $referenced[$column->channel] = true;
            }
        }

        $unresolved = [];
        foreach (array_keys($referenced) as $code) {
            if (null === $this->channelResolver->resolveId($code, $tenant)) {
                $unresolved[] = $code;
            }
        }

        if ([] !== $unresolved) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Export references channel(s) that no longer exist in this tenant: %s. Remove or remap the channel columns before exporting.',
                implode(', ', $unresolved),
            ));
        }
    }

    private function structuralCount(ExportEntityType $type, Tenant $tenant): int
    {
        foreach ($this->structuralBuilders as $builder) {
            if ($builder->supports($type)) {
                return $builder->count($tenant);
            }
        }

        throw new UnprocessableEntityHttpException(sprintf('No structural builder for entity_type=%s.', $type->value));
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
     *
     * @return array<string, mixed>|null
     */
    private function parseFilter(array $payload): ?array
    {
        // Same key as POST /api/products/export (#2987). Keep the legacy
        // `filter` alias for saved clients while the wizard now sends only the
        // canonical snapshot field.
        $value = $payload['filter_snapshot'] ?? $payload['filter'] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_array($value)) {
            throw new BadRequestHttpException('filter_snapshot must be a JSON object or null.');
        }
        $dsl = [];
        foreach ($value as $key => $val) {
            if (!\is_string($key)) {
                throw new BadRequestHttpException('filter_snapshot must be a JSON object (string keys).');
            }
            $dsl[$key] = $val;
        }

        return $dsl;
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
            throw new BadRequestHttpException('selected_object_ids must be a non-empty array when target_scope=selected.');
        }

        $ids = [];
        foreach ($value as $id) {
            if (!\is_string($id) || !Uuid::isValid($id)) {
                throw new BadRequestHttpException('selected_object_ids must contain RFC 4122 UUID strings.');
            }
            $ids[$id] = true;
        }

        return array_keys($ids);
    }

    /** @param array<string, mixed> $payload */
    private function parseIncludeVariants(array $payload): bool
    {
        $value = $payload['include_variants'] ?? true;
        if (!\is_bool($value)) {
            throw new BadRequestHttpException('include_variants must be boolean.');
        }

        return $value;
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
}
