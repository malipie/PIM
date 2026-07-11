<?php

declare(strict_types=1);

namespace App\Notification\Application;

use App\Notification\Contracts\NotifierPort;
use App\Notification\Domain\Entity\Notification;
use App\Shared\Application\TenantContext;
use App\Shared\Infrastructure\Mercure\MercureSubscribeTopics;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;
use Throwable;

use const JSON_THROW_ON_ERROR;

/**
 * WFL-P2-02 (#2421) — persists one row per recipient and pushes a live
 * Mercure update on each user's notification topic. Rows are flushed
 * immediately: the fan-out runs post-flush from Messenger handlers, so
 * there is no ambient flush to ride. Hub failures never abort the
 * write (Mercure is a delivery channel, not the source of truth) —
 * same contract as MercurePublisher.
 */
final readonly class PersistingNotifier implements NotifierPort
{
    private LoggerInterface $logger;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
        private HubInterface $hub,
        private string $topicBase = 'https://pim.localhost',
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function notifyUsers(array $userIds, string $type, ?array $payload = null): void
    {
        if ([] === $userIds) {
            return;
        }

        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            return;
        }

        $created = [];
        foreach ($userIds as $userId) {
            $notification = new Notification($userId, $type, $payload);
            $notification->assignTenant($tenant);
            $this->entityManager->persist($notification);
            $created[] = $notification;
        }
        $this->entityManager->flush();

        foreach ($created as $notification) {
            $this->pushLive($tenant->getId(), $notification);
        }
    }

    private function pushLive(Uuid $tenantId, Notification $notification): void
    {
        $topic = MercureSubscribeTopics::userNotifications(
            $tenantId,
            $this->topicBase,
            $notification->getUserId()->toRfc4122(),
        );

        try {
            $this->hub->publish(new Update(
                topics: [$topic],
                data: \json_encode([
                    'id' => $notification->getId()->toRfc4122(),
                    'type' => $notification->getType(),
                    'payload' => $notification->getPayload(),
                    'created_at' => $notification->getCreatedAt()->format(DateTimeInterface::ATOM),
                ], JSON_THROW_ON_ERROR),
                private: true,
            ));
        } catch (Throwable $e) {
            $this->logger->warning('Notification Mercure publish failed: {message}', [
                'message' => $e->getMessage(),
                'topic' => $topic,
                'notificationId' => $notification->getId()->toRfc4122(),
            ]);
        }
    }
}
