<?php

declare(strict_types=1);

namespace App\Export\Feed\Presentation\Controller;

use App\Export\Feed\Application\Async\FeedProgressPublisher;
use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Entity\FeedRun;
use App\Export\Feed\Domain\Repository\FeedProfileRepositoryInterface;
use App\Export\Feed\Domain\Repository\FeedRunRepositoryInterface;
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
 * XMLF-P4-02 (#1926) — user-requested cancellation of a feed regeneration.
 *
 * Persists the terminal status; the async worker polls it between chunks
 * (Connection::fetchOne) and stops gracefully — partial temp file removed,
 * previous cached artifact untouched, no error state. Mirrors the export
 * cancel endpoint (EXR-15). Auto-registered into OpenAPI (ADR-0020).
 */
final class FeedRunCancelController
{
    public function __construct(
        private readonly FeedProfileRepositoryInterface $feeds,
        private readonly FeedRunRepositoryInterface $runs,
        private readonly FeedProgressPublisher $progress,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
    ) {
    }

    #[Route(
        path: '/api/feeds/{id}/runs/{runId}/cancel',
        name: 'pim_feeds_run_cancel',
        methods: ['POST'],
        requirements: ['id' => '[0-9a-fA-F-]{36}', 'runId' => '[0-9a-fA-F-]{36}'],
    )]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'integration', action: 'admin', anyOf: [
        'integration.admin',
        'settings.integrations.manage',
    ])]
    public function cancel(string $id, string $runId): JsonResponse
    {
        $this->assertTenantUser();

        $feed = $this->feeds->findById(Uuid::fromString($id));
        if (!$feed instanceof FeedProfile) {
            throw new NotFoundHttpException(sprintf('Feed "%s" was not found.', $id));
        }

        $run = $this->runs->findById(Uuid::fromString($runId));
        // A run of another feed is a flat 404 — ids are not interchangeable.
        if (!$run instanceof FeedRun || !$run->getFeedProfileId()->equals($feed->getId())) {
            throw new NotFoundHttpException(sprintf('Feed run "%s" was not found.', $runId));
        }

        if ($run->getStatus()->isTerminal()) {
            throw new ConflictHttpException(sprintf(
                'Feed run is already %s and cannot be cancelled.',
                $run->getStatus()->value,
            ));
        }

        $run->markCancelled();
        $this->runs->save($run);
        $this->progress->status($run);

        return new JsonResponse([
            'id' => $run->getId()->toRfc4122(),
            'feed_id' => $run->getFeedProfileId()->toRfc4122(),
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
