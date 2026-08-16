<?php

declare(strict_types=1);

namespace App\Export\Catalog\Presentation\Controller;

use App\Export\Catalog\Domain\Entity\CatalogRun;
use App\Export\Catalog\Domain\Repository\CatalogRunRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use App\Shared\Application\UserIdentityAware;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

use const DATE_ATOM;

/**
 * CPDF-P3-03 — user-requested cancellation of a PDF catalog generation, the
 * catalog-world mirror of
 * {@see \App\Export\Feed\Presentation\Controller\FeedRunCancelController}.
 *
 * Persists the terminal status; the async worker polls it between chunks
 * ({@see \App\Export\Catalog\Application\Async\GenerateCatalogHandler} onChunk)
 * and stops gracefully — partial temp file removed, previous cached artifact
 * untouched, no error state. Auto-registered into OpenAPI (ADR-0020).
 */
final class CatalogRunCancelController
{
    public function __construct(
        private readonly CatalogRunRepositoryInterface $runs,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
    ) {
    }

    #[Route(
        path: '/api/catalog-runs/{id}/cancel',
        name: 'pim_catalog_runs_cancel',
        methods: ['POST'],
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
    )]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'integration', action: 'admin', anyOf: [
        'integration.admin',
        'exports.run',
    ])]
    public function cancel(string $id): JsonResponse
    {
        $this->assertTenantUser();

        $run = $this->runs->findById(Uuid::fromString($id));
        if (!$run instanceof CatalogRun) {
            // Tenant isolation via RLS/TenantFilter; a miss is a 404 either way.
            throw new NotFoundHttpException(sprintf('Catalog run "%s" was not found.', $id));
        }

        if ($run->getStatus()->isTerminal()) {
            throw new ConflictHttpException(sprintf(
                'Catalog run is already %s and cannot be cancelled.',
                $run->getStatus()->value,
            ));
        }

        $run->markCancelled();
        $this->runs->save($run);

        return new JsonResponse([
            'id' => $run->getId()->toRfc4122(),
            'catalog_id' => $run->getCatalogProfileId()->toRfc4122(),
            'status' => $run->getStatus()->value,
            'completed_at' => $run->getCompletedAt()?->format(DATE_ATOM),
        ]);
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
