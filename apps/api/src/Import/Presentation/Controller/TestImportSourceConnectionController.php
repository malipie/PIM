<?php

declare(strict_types=1);

namespace App\Import\Presentation\Controller;

use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Contracts\Auth\CurrentUserProvider;
use App\Import\Application\Service\HealthCheckService;
use App\Import\Domain\Entity\ImportSource;
use App\Import\Domain\Repository\ImportSourceRepositoryInterface;
use DateTimeInterface;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * VIEW-IMP-03 (#500) — runs a health-check probe against the source
 * and returns the freshly recorded state. The probe itself is owned
 * by {@see HealthCheckService}; this controller is the HTTP edge.
 */
final class TestImportSourceConnectionController
{
    public function __construct(
        private readonly ImportSourceRepositoryInterface $sources,
        private readonly HealthCheckService $healthCheck,
        private readonly CurrentUserProvider $currentUser,
    ) {
    }

    #[Route(
        path: '/api/import-sources/{id}/test-connection',
        name: 'imports_source_test_connection',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    // #2881 — `imports.run`, not the view codes: this reaches out to the
    // remote source, so it belongs to whoever may run an import, not to
    // whoever may read past sessions.
    #[RequiresPermission(module: 'import_source', action: 'read', anyOf: [
        'import_source.read',
        'imports.run',
    ])]
    public function __invoke(string $id): JsonResponse
    {
        $userId = $this->currentUser->userId();
        if (null === $userId) {
            throw new UnauthorizedHttpException('JWT', 'Authenticated user required.');
        }

        try {
            $sourceId = Uuid::fromString($id);
        } catch (InvalidArgumentException) {
            throw new NotFoundHttpException(\sprintf('Import source "%s" was not found.', $id));
        }

        $source = $this->sources->findById($sourceId);
        if (!$source instanceof ImportSource) {
            throw new NotFoundHttpException(\sprintf('Import source "%s" was not found.', $id));
        }
        if ($source->getUserId()->toRfc4122() !== $userId->toRfc4122()) {
            throw new NotFoundHttpException(\sprintf('Import source "%s" was not found.', $id));
        }

        $result = $this->healthCheck->check($source);

        return new JsonResponse([
            'health' => $result->health->value,
            'note' => $result->note,
            'latency_ms' => $result->latencyMs,
            'checked_at' => $source->getHealthCheckedAt()?->format(DateTimeInterface::RFC3339_EXTENDED),
        ], Response::HTTP_OK);
    }
}
