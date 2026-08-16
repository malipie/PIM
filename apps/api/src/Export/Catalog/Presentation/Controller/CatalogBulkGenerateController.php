<?php

declare(strict_types=1);

namespace App\Export\Catalog\Presentation\Controller;

use App\Export\Catalog\Domain\Entity\CatalogRun;
use App\Export\Catalog\Domain\Enum\CatalogRunTrigger;
use App\Export\Catalog\Domain\Message\RunCatalogMessage;
use App\Export\Catalog\Domain\Repository\CatalogProfileRepositoryInterface;
use App\Export\Catalog\Domain\Repository\CatalogRunRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use App\Shared\Application\UserIdentityAware;
use App\Shared\Domain\Tenant;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Bulk PDF catalog generation (ADR-0027 §5, CPDF-P3-04) — "Generuj wszystkie":
 * the Plytix-parity trigger that regenerates a set of catalogs in one call
 * (e.g. every collection sheet after a price update). It is the batch analogue
 * of {@see CatalogRegenerateController}: for each requested catalog it creates a
 * pending {@see CatalogRun} and dispatches a {@see RunCatalogMessage} onto the
 * shared `import` queue, so the same {@see \App\Export\Catalog\Application\Async\GenerateCatalogHandler}
 * drives every run out-of-band.
 *
 * Gated on the SINGLE-item permission (`integration.admin`), so the bulk route
 * can never widen authority beyond generating one catalog (the bulk-endpoint
 * escalation guard). Unknown ids / catalogs of another tenant are silently
 * skipped (RLS/TenantFilter scopes the lookup); the response reports what was
 * dispatched and what was skipped. Auto-registered into OpenAPI by
 * CustomRouteOpenApiFactory (ADR-0020).
 */
final class CatalogBulkGenerateController
{
    /** A single request may not fan out to more than this many runs. */
    private const int MAX_CATALOGS = 100;

    public function __construct(
        private readonly CatalogProfileRepositoryInterface $catalogs,
        private readonly CatalogRunRepositoryInterface $runs,
        private readonly MessageBusInterface $bus,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
    ) {
    }

    #[Route(path: '/api/catalogs/bulk-generate', name: 'pim_catalogs_bulk_generate', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'integration', action: 'admin', anyOf: [
        'integration.admin',
        'exports.view_all',
    ])]
    public function bulkGenerate(Request $request): JsonResponse
    {
        $tenant = $this->resolveTenant();
        $ids = $this->parseCatalogIds($request);

        $dispatched = [];
        $skipped = [];
        foreach ($ids as $id) {
            $catalog = $this->catalogs->findById($id);
            if (null === $catalog) {
                // RLS/TenantFilter already scoped the lookup — a miss (unknown
                // or another tenant's catalog) is skipped, never leaked.
                $skipped[] = $id->toRfc4122();

                continue;
            }

            $run = new CatalogRun($catalog->getId(), CatalogRunTrigger::Manual);
            $run->assignTenant($tenant);
            $this->runs->save($run);
            $this->bus->dispatch(new RunCatalogMessage($run->getId(), $tenant->getId()));

            $dispatched[] = [
                'catalog_id' => $catalog->getId()->toRfc4122(),
                'run_id' => $run->getId()->toRfc4122(),
            ];
        }

        return new JsonResponse(
            [
                'dispatched' => $dispatched,
                'skipped' => $skipped,
            ],
            Response::HTTP_ACCEPTED,
        );
    }

    /**
     * @return list<Uuid>
     */
    private function parseCatalogIds(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);
        $rawIds = \is_array($payload) ? ($payload['catalog_ids'] ?? null) : null;
        if (!\is_array($rawIds) || [] === $rawIds) {
            throw new BadRequestHttpException('Field "catalog_ids" must be a non-empty array of catalog UUIDs.');
        }
        if (\count($rawIds) > self::MAX_CATALOGS) {
            throw new BadRequestHttpException(sprintf('At most %d catalogs can be generated per request.', self::MAX_CATALOGS));
        }

        $ids = [];
        foreach ($rawIds as $raw) {
            if (!\is_string($raw) || !Uuid::isValid($raw)) {
                throw new BadRequestHttpException('Every "catalog_ids" entry must be a valid UUID.');
            }
            $ids[] = Uuid::fromString($raw);
        }

        return $ids;
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
