<?php

declare(strict_types=1);

namespace App\Export\Feed\Presentation\Controller;

use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Entity\FeedRun;
use App\Export\Feed\Domain\Enum\FeedRunTrigger;
use App\Export\Feed\Domain\Message\RunFeedMessage;
use App\Export\Feed\Domain\Repository\FeedProfileRepositoryInterface;
use App\Export\Feed\Domain\Repository\FeedRunRepositoryInterface;
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
 * Manual feed regeneration (ADR-0023 §6.6, XMLF-P4-01/P4-02) — the "Regeneruj
 * teraz" trigger. Creates a pending {@see FeedRun} and dispatches a
 * {@see RunFeedMessage} onto the `import` queue; {@see \App\Export\Feed\Application\Async\FeedRunHandler}
 * drives the regeneration out-of-band and streams progress over Mercure
 * (`mercure_topic` in the response — the monitor subscribes there).
 *
 * In dev/prod the response is a true 202 (run pending, worker picks it up);
 * in the test env the `import` transport is sync://, so the run completes
 * inline and the serialized state already reflects the terminal status —
 * which is also why the response reloads both entities before serializing.
 * Auto-registered into OpenAPI by CustomRouteOpenApiFactory (ADR-0020).
 */
final class FeedRegenerateController
{
    public function __construct(
        private readonly FeedProfileRepositoryInterface $feeds,
        private readonly FeedRunRepositoryInterface $runs,
        private readonly MessageBusInterface $bus,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
        private readonly string $topicBase,
    ) {
    }

    #[Route(path: '/api/feeds/{id}/regenerate', name: 'pim_feeds_regenerate', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'integration', action: 'admin')]
    public function regenerate(string $id): JsonResponse
    {
        $tenant = $this->resolveTenant();
        $feed = $this->loadOrFail($id);

        $run = new FeedRun($feed->getId(), FeedRunTrigger::Manual);
        $this->runs->save($run);

        $this->bus->dispatch(new RunFeedMessage($run->getId(), $tenant->getId()));

        // Sync transport (test env) completes the run inside dispatch() and
        // the handler's EM clears detach our references — reload both so the
        // response reflects the actual current state.
        $freshRun = $this->runs->findById($run->getId()) ?? $run;
        $freshFeed = $this->feeds->findById($feed->getId()) ?? $feed;

        return new JsonResponse(
            $this->serialize($freshFeed, $freshRun, $tenant),
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
    private function serialize(FeedProfile $feed, FeedRun $run, Tenant $tenant): array
    {
        return [
            'run' => [
                'id' => $run->getId()->toRfc4122(),
                'status' => $run->getStatus()->value,
                'trigger' => $run->getTrigger()->value,
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
            // The monitor subscribes here for live progress/status (P4-02).
            'mercure_topic' => MercureSubscribeTopics::feedRuns(
                $tenant->getId(),
                $this->topicBase,
                $feed->getId()->toRfc4122(),
            ),
        ];
    }
}
