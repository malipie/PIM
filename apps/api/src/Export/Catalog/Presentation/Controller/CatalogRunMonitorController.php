<?php

declare(strict_types=1);

namespace App\Export\Catalog\Presentation\Controller;

use App\Export\Catalog\Domain\Entity\CatalogProfile;
use App\Export\Catalog\Domain\Entity\CatalogRun;
use App\Export\Catalog\Domain\Enum\CatalogRunStatus;
use App\Export\Catalog\Domain\Enum\CatalogStatus;
use App\Export\Catalog\Domain\Repository\CatalogProfileRepositoryInterface;
use App\Export\Catalog\Domain\Repository\CatalogRunRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use App\Shared\Application\UserIdentityAware;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Global catalog monitor (CPDF-P3-03, ADR-0027 §6.3) — the read side behind the
 * catalog monitor screen: the tenant-wide run history (RunsTable) and the health
 * KPI tiles (Regeneracje 24h / Strony wygenerowane / Błędy 24h). Read-only
 * projections; the catalog-world analogue of
 * {@see \App\Export\Feed\Presentation\Controller\FeedRunMonitorController}.
 * Auto-registered into OpenAPI (ADR-0020).
 *
 * Runs are keyset-paginated over the UUIDv7 id (== started_at order); the
 * `cursor` query param round-trips the last item's id. `status` filters the
 * DISPLAY health: success (done) | error.
 */
final class CatalogRunMonitorController
{
    private const int DEFAULT_LIMIT = 25;
    private const int MAX_LIMIT = 100;

    public function __construct(
        private readonly CatalogRunRepositoryInterface $runs,
        private readonly CatalogProfileRepositoryInterface $catalogs,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
    ) {
    }

    #[Route(path: '/api/catalog-runs', name: 'pim_catalog_runs_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all')]
    public function list(Request $request): JsonResponse
    {
        $tenant = $this->resolveTenant();
        [$health, $cursor, $limit] = $this->readListParams($request);

        $page = $this->runs->findPage(null, $health, $cursor, $limit);

        // Names for the global table — one indexed load for the whole page
        // (a tenant has dozens of catalogs, not thousands).
        $profiles = [];
        foreach ($this->catalogs->findByTenant($tenant) as $profile) {
            $profiles[$profile->getId()->toRfc4122()] = $profile;
        }

        $items = array_map(
            fn (CatalogRun $run): array => $this->serializeRun($run, $profiles[$run->getCatalogProfileId()->toRfc4122()] ?? null),
            $page,
        );

        return new JsonResponse([
            'items' => $items,
            'next_cursor' => [] !== $page && \count($page) === $limit
                ? $page[\count($page) - 1]->getId()->toRfc4122()
                : null,
        ]);
    }

    #[Route(path: '/api/catalog-runs/kpi', name: 'pim_catalog_runs_kpi', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all')]
    public function kpi(): JsonResponse
    {
        $tenant = $this->resolveTenant();

        $kpi = $this->runs->kpi24h($tenant, new DateTimeImmutable());

        // Current publication snapshot: what the public URLs serve right now
        // (design tile "Strony syndykowane" — inventory, not a 24h flow).
        $pagesPublished = 0;
        $lastRegeneratedAt = null;
        $statusCounts = ['active' => 0, 'paused' => 0, 'error' => 0];
        foreach ($this->catalogs->findByTenant($tenant) as $profile) {
            $pagesPublished += $profile->getCachedPageCount() ?? 0;
            $cachedAt = $profile->getCachedAt();
            if (null !== $cachedAt && (null === $lastRegeneratedAt || $cachedAt > $lastRegeneratedAt)) {
                $lastRegeneratedAt = $cachedAt;
            }
            $key = match ($profile->getStatus()) {
                CatalogStatus::Active => 'active',
                CatalogStatus::Paused => 'paused',
                CatalogStatus::Error => 'error',
            };
            ++$statusCounts[$key];
        }

        return new JsonResponse([
            ...$kpi,
            'pages_published' => $pagesPublished,
            'last_regenerated_at' => $lastRegeneratedAt?->format(DateTimeInterface::ATOM),
            'catalogs' => $statusCounts,
        ]);
    }

    /**
     * @return array{0: string|null, 1: Uuid|null, 2: int}
     */
    private function readListParams(Request $request): array
    {
        $status = $request->query->get('status');
        $health = \in_array($status, ['success', 'error'], true) ? $status : null;

        $cursorRaw = $request->query->get('cursor');
        // An invalid cursor serves the first page — stale URLs never 500.
        $cursor = (\is_string($cursorRaw) && Uuid::isValid($cursorRaw)) ? Uuid::fromString($cursorRaw) : null;

        $limit = max(1, min(self::MAX_LIMIT, $request->query->getInt('limit', self::DEFAULT_LIMIT)));

        return [$health, $cursor, $limit];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRun(CatalogRun $run, ?CatalogProfile $profile): array
    {
        return [
            'id' => $run->getId()->toRfc4122(),
            'catalog_id' => $run->getCatalogProfileId()->toRfc4122(),
            'catalog_name' => $profile?->getName(),
            'catalog_code' => $profile?->getCode(),
            'template_kind' => $profile?->getTemplateKind()->value,
            'trigger' => $run->getTrigger()->value,
            'status' => $run->getStatus()->value,
            'health' => self::health($run),
            'page_count' => $run->getPageCount(),
            'byte_size' => $run->getByteSize(),
            'item_count' => $run->getItemCount(),
            'duration_ms' => $run->getDurationMs(),
            'error_message' => $run->getErrorMessage(),
            'started_at' => $run->getStartedAt()?->format(DateTimeInterface::ATOM),
            'completed_at' => $run->getCompletedAt()?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Display status for the monitor chips.
     */
    public static function health(CatalogRun $run): string
    {
        return match ($run->getStatus()) {
            CatalogRunStatus::Done => 'success',
            CatalogRunStatus::Error => 'error',
            CatalogRunStatus::Pending => 'pending',
            CatalogRunStatus::Running => 'running',
            CatalogRunStatus::Cancelled => 'cancelled',
        };
    }

    private function resolveTenant(): Tenant
    {
        if (!$this->security->getUser() instanceof UserIdentityAware) {
            throw new AccessDeniedHttpException('Authenticated user identity required.');
        }
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new AccessDeniedHttpException('Tenant context required.');
        }

        return $tenant;
    }
}
