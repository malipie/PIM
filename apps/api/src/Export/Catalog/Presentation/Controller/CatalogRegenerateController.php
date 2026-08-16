<?php

declare(strict_types=1);

namespace App\Export\Catalog\Presentation\Controller;

use App\Export\Catalog\Domain\Entity\CatalogProfile;
use App\Export\Catalog\Domain\Entity\CatalogRun;
use App\Export\Catalog\Domain\Enum\CatalogRunTrigger;
use App\Export\Catalog\Domain\Message\RunCatalogMessage;
use App\Export\Catalog\Domain\Repository\CatalogProfileRepositoryInterface;
use App\Export\Catalog\Domain\Repository\CatalogRunRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use App\Shared\Application\UserIdentityAware;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Mercure\MercureSubscribeTopics;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

use const DATE_ATOM;

/**
 * Manual PDF catalog generation (ADR-0027 §6.3, CPDF-P3-03) — the "Generuj
 * teraz" trigger, the catalog-world analogue of {@see \App\Export\Feed\Presentation\Controller\FeedRegenerateController}.
 * Creates a pending {@see CatalogRun}, assigns the tenant and dispatches a
 * {@see RunCatalogMessage} onto the shared `import` queue;
 * {@see \App\Export\Catalog\Application\Async\GenerateCatalogHandler} drives the
 * regeneration out-of-band and streams progress over Mercure (`mercure_topic`
 * in the response — the monitor subscribes there).
 *
 * In dev/prod the response is a true 202 (run pending, worker picks it up);
 * in the test env the `import` transport is sync://, so the run completes inline
 * and the serialized state already reflects the terminal status — which is also
 * why the response reloads the run before serializing. Auto-registered into
 * OpenAPI by CustomRouteOpenApiFactory (ADR-0020).
 */
final class CatalogRegenerateController
{
    public function __construct(
        private readonly CatalogProfileRepositoryInterface $catalogs,
        private readonly CatalogRunRepositoryInterface $runs,
        private readonly MessageBusInterface $bus,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
        private readonly string $topicBase,
    ) {
    }

    #[Route(path: '/api/catalogs/{id}/generate', name: 'pim_catalogs_generate', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'integration', action: 'admin', anyOf: [
        'integration.admin',
        'exports.view_all',
    ])]
    public function generate(string $id): JsonResponse
    {
        $tenant = $this->resolveTenant();
        $catalog = $this->loadOrFail($id);

        $run = new CatalogRun($catalog->getId(), CatalogRunTrigger::Manual);
        $run->assignTenant($tenant);
        $this->runs->save($run);

        $this->bus->dispatch(new RunCatalogMessage($run->getId(), $tenant->getId()));

        // Sync transport (test env) completes the run inside dispatch() and
        // the handler's EM clear detaches our reference — reload the run so the
        // response reflects the actual current state.
        $freshRun = $this->runs->findById($run->getId()) ?? $run;

        return new JsonResponse(
            [
                'run' => [
                    'id' => $freshRun->getId()->toRfc4122(),
                    'catalog_id' => $freshRun->getCatalogProfileId()->toRfc4122(),
                    'status' => $freshRun->getStatus()->value,
                    'trigger' => $freshRun->getTrigger()->value,
                    'page_count' => $freshRun->getPageCount(),
                    'byte_size' => $freshRun->getByteSize(),
                    'item_count' => $freshRun->getItemCount(),
                    'error_message' => $freshRun->getErrorMessage(),
                    'started_at' => $freshRun->getStartedAt()?->format(DATE_ATOM),
                    'completed_at' => $freshRun->getCompletedAt()?->format(DATE_ATOM),
                ],
                // The monitor subscribes here for live progress/status (P3-03).
                'mercure_topic' => MercureSubscribeTopics::catalogRuns(
                    $tenant->getId(),
                    $this->topicBase,
                    $catalog->getId()->toRfc4122(),
                ),
            ],
            Response::HTTP_ACCEPTED,
        );
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

    private function loadOrFail(string $id): CatalogProfile
    {
        $catalog = $this->catalogs->findById(Uuid::fromString($id));
        if (null === $catalog) {
            // Tenant isolation via RLS/TenantFilter; a miss is a 404 either way.
            throw new NotFoundHttpException(sprintf('Catalog "%s" was not found.', $id));
        }

        return $catalog;
    }
}
