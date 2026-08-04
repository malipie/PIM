<?php

declare(strict_types=1);

namespace App\Import\Presentation\Controller;

use App\Backup\Domain\Entity\Backup;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Contracts\Auth\CurrentUserProvider;
use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Enum\ImportSessionStatus;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * VIEW-IMP-01 — listing endpoint for the sessions hub.
 *
 * Hydra-shaped collection so Refine's `useList` + the
 * minimal-Hydra DataProvider can consume it directly. Filters:
 * `status` (single enum value), `q` (file_name or profile_name
 * ILIKE substring), `page` (1-based, pageSize 50 default, capped
 * at 200). Tenant-scoped + owner-scoped: each user sees only their
 * own sessions, matching the rest of the Import surface.
 */
final class ListImportSessionsController
{
    private const int DEFAULT_PAGE_SIZE = 50;
    private const int MAX_PAGE_SIZE = 200;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CurrentUserProvider $currentUser,
    ) {
    }

    #[Route(
        path: '/api/import-sessions',
        name: 'imports_list',
        methods: ['GET'],
    )]
    #[RequiresPermission(module: 'import_session', action: 'read')]
    public function __invoke(Request $request): JsonResponse
    {
        $userId = $this->currentUser->userId();
        if (null === $userId) {
            throw new UnauthorizedHttpException('JWT', 'Authenticated user required.');
        }

        $page = max(1, (int) $request->query->get('page', '1'));
        $pageSize = max(1, min(self::MAX_PAGE_SIZE, (int) $request->query->get('pageSize', (string) self::DEFAULT_PAGE_SIZE)));

        $rawStatus = $request->query->get('status', '');
        $status = null;
        if ('' !== $rawStatus) {
            $candidate = ImportSessionStatus::tryFrom($rawStatus);
            if (!$candidate instanceof ImportSessionStatus) {
                throw new BadRequestHttpException(\sprintf('Unknown status filter "%s".', $rawStatus));
            }
            $status = $candidate;
        }

        $query = trim($request->query->get('q', ''));

        $qb = $this->entityManager->createQueryBuilder()
            ->select('s', 'p', 'b')
            ->from(ImportSession::class, 's')
            ->leftJoin('s.profile', 'p')
            // IMP2-2.10 (#1486) — eager-join the pre-import backup so the hub
            // badge does not trigger an N+1 across the page of sessions.
            ->leftJoin('s.backupSnapshot', 'b')
            ->where('s.tenant = :tenant')
            ->andWhere('s.userId = :userId')
            ->orderBy('s.createdAt', 'DESC')
            ->setParameter('tenant', $this->currentUser->tenant())
            ->setParameter('userId', $userId);

        if ($status instanceof ImportSessionStatus) {
            $qb->andWhere('s.status = :status')->setParameter('status', $status->value);
        }

        if ('' !== $query) {
            $qb->andWhere('(LOWER(s.fileName) LIKE :q OR LOWER(p.name) LIKE :q)')
                ->setParameter('q', '%'.mb_strtolower($query).'%');
        }

        $countQb = clone $qb;
        $countQb->resetDQLPart('orderBy');
        $countQb->select('COUNT(DISTINCT s.id)');
        /** @var int $totalItems */
        $totalItems = (int) $countQb->getQuery()->getSingleScalarResult();

        $qb->setFirstResult(($page - 1) * $pageSize)->setMaxResults($pageSize);

        /** @var list<ImportSession> $sessions */
        $sessions = $qb->getQuery()->getResult();

        $members = array_map(
            static fn (ImportSession $session): array => self::serialize($session),
            $sessions,
        );

        return new JsonResponse([
            'member' => $members,
            'totalItems' => $totalItems,
            'page' => $page,
            'pageSize' => $pageSize,
        ], Response::HTTP_OK);
    }

    /**
     * @return array<string, mixed>
     */
    private static function serialize(ImportSession $session): array
    {
        $profile = $session->getProfile();
        $startedAt = $session->getStartedAt();
        $completedAt = $session->getCompletedAt();
        $durationSec = null;
        if ($startedAt instanceof DateTimeImmutable && $completedAt instanceof DateTimeImmutable) {
            $durationSec = max(0, $completedAt->getTimestamp() - $startedAt->getTimestamp());
        }

        return [
            'id' => $session->getId()->toRfc4122(),
            'status' => $session->getStatus()->value,
            'file_name' => $session->getFileName(),
            'file_size_bytes' => $session->getFileSizeBytes(),
            'total_rows' => $session->getTotalRows(),
            'success_count' => $session->getSuccessCount(),
            'error_count' => $session->getErrorCount(),
            'updated_count' => $session->getUpdatedCount(),
            'skipped_count' => $session->getSkippedCount(),
            'started_at' => $startedAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            'completed_at' => $completedAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            'rollback_until' => $session->getRollbackUntil()?->format(DateTimeInterface::RFC3339_EXTENDED),
            'duration_sec' => $durationSec,
            'profile_name' => $profile?->getName(),
            'profile_id' => $profile?->getId()->toRfc4122(),
            'target_object_type_code' => $session->getTargetObjectType()?->getCode(),
            'structural_kind' => $session->getStructuralKind(),
            'mode' => $session->getMode()->value,
            'backup' => self::serializeBackup($session->getBackupSnapshot()),
        ];
    }

    /**
     * @return array{id: string, status: string, started_at: string}|null
     */
    private static function serializeBackup(?Backup $backup): ?array
    {
        if (!$backup instanceof Backup) {
            return null;
        }

        return [
            'id' => $backup->getId()->toRfc4122(),
            'status' => $backup->getStatus()->value,
            'started_at' => $backup->getStartedAt()->format(DateTimeInterface::RFC3339_EXTENDED),
        ];
    }
}
