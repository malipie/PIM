<?php

declare(strict_types=1);

namespace App\Notification\Presentation\Controller;

use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Contracts\Auth\CurrentUserProvider;
use App\Notification\Domain\Entity\Notification;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * WFL-P2-03 (#2422) — own-data notification surface for the top-bar
 * bell: cursor-paged list (UUIDv7 ids ARE the cursor), unread count and
 * read receipts. Every query is pinned to the token principal — a
 * foreign notification id resolves to 404, never a 403 leak (IDOR
 * covered by ApiTestCase).
 *
 * Gated `workflow.view` (every seeded role holds it) because the only
 * MVP producer is the workflow fan-out; when another module starts
 * writing notifications the gate moves to a dedicated code (P6-01
 * revisits).
 */
final class NotificationsController
{
    private const int DEFAULT_LIMIT = 20;
    private const int MAX_LIMIT = 100;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CurrentUserProvider $currentUser,
    ) {
    }

    #[Route(path: '/api/notifications', name: 'notifications_list', methods: ['GET'])]
    #[RequiresPermission(module: 'workflow', action: 'view')]
    public function list(Request $request): JsonResponse
    {
        $userId = $this->requireUserId();

        $limit = \min(self::MAX_LIMIT, \max(1, $request->query->getInt('limit', self::DEFAULT_LIMIT)));
        $unreadOnly = $request->query->getBoolean('unread');
        $beforeRaw = $request->query->getString('before');
        $before = null;
        if ('' !== $beforeRaw) {
            if (!Uuid::isValid($beforeRaw)) {
                throw new UnprocessableEntityHttpException('The "before" cursor must be a notification id (UUID).');
            }
            $before = Uuid::fromString($beforeRaw);
        }

        $builder = $this->entityManager->createQueryBuilder()
            ->select('n')
            ->from(Notification::class, 'n')
            ->where('n.userId = :user')
            ->setParameter('user', $userId)
            ->orderBy('n.id', 'DESC')
            ->setMaxResults($limit + 1);
        if ($unreadOnly) {
            $builder->andWhere('n.readAt IS NULL');
        }
        if (null !== $before) {
            $builder->andWhere('n.id < :before')->setParameter('before', $before);
        }

        /** @var list<Notification> $rows */
        $rows = $builder->getQuery()->getResult();
        $hasMore = \count($rows) > $limit;
        $rows = \array_slice($rows, 0, $limit);

        $unreadCount = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(n.id)')
            ->from(Notification::class, 'n')
            ->where('n.userId = :user')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('user', $userId)
            ->getQuery()
            ->getSingleScalarResult();

        return new JsonResponse([
            'items' => \array_map(self::serialize(...), $rows),
            'unread_count' => $unreadCount,
            'next_cursor' => $hasMore && [] !== $rows ? $rows[\count($rows) - 1]->getId()->toRfc4122() : null,
        ]);
    }

    #[Route(
        path: '/api/notifications/{id}/read',
        name: 'notifications_mark_read',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    #[RequiresPermission(module: 'workflow', action: 'view')]
    public function markRead(string $id): JsonResponse
    {
        $userId = $this->requireUserId();

        $notification = $this->entityManager->find(Notification::class, Uuid::fromString($id));
        if (null === $notification || !$notification->getUserId()->equals($userId)) {
            throw new NotFoundHttpException('Notification not found.');
        }

        $notification->markRead();
        $this->entityManager->flush();

        return new JsonResponse(['id' => $notification->getId()->toRfc4122(), 'read' => true]);
    }

    #[Route(path: '/api/notifications/read-all', name: 'notifications_read_all', methods: ['POST'])]
    #[RequiresPermission(module: 'workflow', action: 'view')]
    public function readAll(): JsonResponse
    {
        $userId = $this->requireUserId();

        $updated = $this->entityManager->createQueryBuilder()
            ->update(Notification::class, 'n')
            ->set('n.readAt', ':now')
            ->where('n.userId = :user')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('now', new DateTimeImmutable())
            ->setParameter('user', $userId)
            ->getQuery()
            ->execute();

        return new JsonResponse(['marked_read' => $updated]);
    }

    private function requireUserId(): Uuid
    {
        $userId = $this->currentUser->userId();
        if (null === $userId) {
            throw new UnauthorizedHttpException('Bearer', 'Authentication required.');
        }

        return $userId;
    }

    /**
     * @return array<string, mixed>
     */
    private static function serialize(Notification $notification): array
    {
        return [
            'id' => $notification->getId()->toRfc4122(),
            'type' => $notification->getType(),
            'payload' => $notification->getPayload(),
            'read_at' => $notification->getReadAt()?->format(DateTimeInterface::ATOM),
            'created_at' => $notification->getCreatedAt()->format(DateTimeInterface::ATOM),
        ];
    }
}
