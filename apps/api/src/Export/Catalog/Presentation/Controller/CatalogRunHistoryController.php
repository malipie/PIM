<?php

declare(strict_types=1);

namespace App\Export\Catalog\Presentation\Controller;

use App\Export\Catalog\Domain\Entity\CatalogProfile;
use App\Export\Catalog\Domain\Entity\CatalogRun;
use App\Export\Catalog\Domain\Repository\CatalogProfileRepositoryInterface;
use App\Export\Catalog\Domain\Repository\CatalogRunRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use App\Shared\Application\UserIdentityAware;
use DateTimeInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Per-catalog run history (CPDF-P3-03, ADR-0027 §6.3) — the read side behind
 * the catalog detail screen: the recent runs of one catalog, newest first. The
 * catalog-world analogue of
 * {@see \App\Export\Feed\Presentation\Controller\FeedRunHistoryController}.
 * Read-only projection; auto-registered into OpenAPI (ADR-0020).
 */
final class CatalogRunHistoryController
{
    private const int DEFAULT_LIMIT = 25;
    private const int MAX_LIMIT = 100;

    public function __construct(
        private readonly CatalogProfileRepositoryInterface $catalogs,
        private readonly CatalogRunRepositoryInterface $runs,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
    ) {
    }

    #[Route(path: '/api/catalogs/{id}/runs', name: 'pim_catalog_runs_history', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'exports', action: 'view_all')]
    public function list(string $id, Request $request): JsonResponse
    {
        $this->assertTenantUser();
        $catalog = $this->loadCatalogOrFail($id);

        $limit = max(1, min(self::MAX_LIMIT, $request->query->getInt('limit', self::DEFAULT_LIMIT)));

        $runs = $this->runs->findByCatalogProfile($catalog->getId(), $limit);

        return new JsonResponse([
            'items' => array_map($this->serializeRun(...), $runs),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRun(CatalogRun $run): array
    {
        return [
            'id' => $run->getId()->toRfc4122(),
            'catalog_id' => $run->getCatalogProfileId()->toRfc4122(),
            'trigger' => $run->getTrigger()->value,
            'status' => $run->getStatus()->value,
            'health' => CatalogRunMonitorController::health($run),
            'page_count' => $run->getPageCount(),
            'byte_size' => $run->getByteSize(),
            'item_count' => $run->getItemCount(),
            'duration_ms' => $run->getDurationMs(),
            'error_message' => $run->getErrorMessage(),
            'started_at' => $run->getStartedAt()?->format(DateTimeInterface::ATOM),
            'completed_at' => $run->getCompletedAt()?->format(DateTimeInterface::ATOM),
        ];
    }

    private function loadCatalogOrFail(string $id): CatalogProfile
    {
        $catalog = $this->catalogs->findById(Uuid::fromString($id));
        if (null === $catalog) {
            // Tenant isolation via RLS/TenantFilter; a miss is a 404 either way.
            throw new NotFoundHttpException(sprintf('Catalog "%s" was not found.', $id));
        }

        return $catalog;
    }

    private function assertTenantUser(): void
    {
        if (!$this->security->getUser() instanceof UserIdentityAware) {
            throw new AccessDeniedHttpException('Authenticated user identity required.');
        }
        if (null === $this->tenantContext->get()) {
            throw new AccessDeniedHttpException('Tenant context required.');
        }
    }
}
