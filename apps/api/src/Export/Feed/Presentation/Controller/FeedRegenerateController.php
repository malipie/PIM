<?php

declare(strict_types=1);

namespace App\Export\Feed\Presentation\Controller;

use App\Export\Feed\Application\Generator\FeedRegenerator;
use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Entity\FeedRun;
use App\Export\Feed\Domain\Enum\FeedRunTrigger;
use App\Export\Feed\Domain\Repository\FeedProfileRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use App\Shared\Application\UserIdentityAware;
use App\Shared\Domain\Tenant;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

use const DATE_ATOM;

/**
 * Manual feed regeneration (ADR-0023 §6.6, XMLF-P4-01) — the "Regeneruj teraz"
 * trigger. Runs the regeneration synchronously through {@see FeedRegenerator}
 * (generate → cache upload → record pointer) and returns the resulting
 * {@see FeedRun} summary so the UI can render the health report immediately.
 *
 * Async dispatch (a RunFeedMessage on the `import` transport) and cron-driven
 * scheduling are XMLF-P4-02 follow-ups; the manual trigger runs inline so it is
 * smoke-testable end-to-end today. Auto-registered into OpenAPI by
 * CustomRouteOpenApiFactory (ADR-0020).
 */
final class FeedRegenerateController
{
    public function __construct(
        private readonly FeedProfileRepositoryInterface $feeds,
        private readonly FeedRegenerator $regenerator,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
    ) {
    }

    #[Route(path: '/api/feeds/{id}/regenerate', name: 'pim_feeds_regenerate', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'integration', action: 'admin')]
    public function regenerate(string $id): JsonResponse
    {
        $this->resolveTenant();
        $feed = $this->loadOrFail($id);

        $run = $this->regenerator->regenerate($feed, FeedRunTrigger::Manual);

        return new JsonResponse($this->serialize($feed, $run), Response::HTTP_ACCEPTED);
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

    private function loadOrFail(string $id): FeedProfile
    {
        $feed = $this->feeds->findById(Uuid::fromString($id));
        if (null === $feed) {
            // Tenant isolation via RLS/TenantFilter; a miss is a 404 either way.
            throw new NotFoundHttpException(sprintf('Feed "%s" was not found.', $id));
        }

        return $feed;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(FeedProfile $feed, FeedRun $run): array
    {
        return [
            'run' => [
                'id' => $run->getId()->toRfc4122(),
                'status' => $run->getStatus()->value,
                'item_count' => $run->getItemCount(),
                'skipped_count' => $run->getSkippedCount(),
                'warning_count' => $run->getWarningCount(),
                'file_size_bytes' => $run->getFileSizeBytes(),
                'duration_ms' => $run->getDurationMs(),
            ],
            'cache' => [
                'file_path' => $feed->getCachedFilePath(),
                'file_size' => $feed->getCachedFileSize(),
                'item_count' => $feed->getCachedItemCount(),
                'generated_at' => $feed->getCachedAt()?->format(DATE_ATOM),
            ],
        ];
    }
}
