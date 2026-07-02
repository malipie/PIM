<?php

declare(strict_types=1);

namespace App\ApiConfigurator\Application\Subscriber;

use App\ApiConfigurator\Domain\Entity\WebhookDelivery;
use App\ApiConfigurator\Domain\Message\WebhookDeliveryMessage;
use App\ApiConfigurator\Domain\Repository\ApiProfileRepositoryInterface;
use App\ApiConfigurator\Domain\Repository\WebhookDeliveryRepositoryInterface;
use App\Catalog\Contracts\BulkGuard;
use App\Catalog\Contracts\Event\ObjectArchived;
use App\Catalog\Contracts\Event\ObjectAttributesChanged;
use App\Catalog\Contracts\Event\ObjectCreated;
use App\Catalog\Contracts\Event\ObjectEnabledChanged;
use App\Catalog\Contracts\Event\ObjectPublished;
use App\Shared\Application\TenantContext;
use App\Shared\Contracts\Event\AgentRunAwaitingApproval;
use App\Shared\Contracts\Event\AgentRunCompleted;
use App\Shared\Domain\DomainEvent;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Fans Catalog domain events out to per-profile outbound webhooks (APIC-P4-05).
 *
 * Each matching profile gets a {@see WebhookDelivery} audit row (status
 * `pending`) and a {@see WebhookDeliveryMessage} dispatched to the async
 * transport, where {@see \App\ApiConfigurator\Application\Handler\WebhookDeliveryHandler}
 * POSTs the signed body with retry + dead-letter. This replaces the previous
 * inline best-effort POST (#93 follow-up): history is now durable and failures
 * back off instead of being dropped after one try.
 *
 * Still best-effort toward the originating write: dispatch is wrapped so a
 * synchronous-transport failure (dev/test, where `async` aliases `sync://`)
 * never bubbles into the write path. In production the dispatch only enqueues;
 * the worker owns retry. Bulk flows are skipped (see {@see fanOut}).
 */
final readonly class WebhookDeliverySubscriber
{
    public function __construct(
        private ApiProfileRepositoryInterface $profiles,
        private WebhookDeliveryRepositoryInterface $deliveries,
        private MessageBusInterface $bus,
        private BulkGuard $bulkContext,
        private TenantContext $tenantContext,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[AsMessageHandler]
    public function onObjectCreated(ObjectCreated $event): void
    {
        $this->fanOut('object.created.'.$event->kind->value, $event, [
            'objectId' => $event->aggregateId(),
            'kind' => $event->kind->value,
            'code' => $event->code,
        ]);
    }

    #[AsMessageHandler]
    public function onObjectAttributesChanged(ObjectAttributesChanged $event): void
    {
        $this->fanOut('object.attributes_changed', $event, [
            'objectId' => $event->aggregateId(),
            'changedAttributeCodes' => $event->changedAttributeCodes,
        ]);
    }

    #[AsMessageHandler]
    public function onObjectEnabledChanged(ObjectEnabledChanged $event): void
    {
        $this->fanOut('object.enabled_changed', $event, [
            'objectId' => $event->aggregateId(),
            'enabled' => $event->enabled,
        ]);
    }

    #[AsMessageHandler]
    public function onObjectPublished(ObjectPublished $event): void
    {
        $this->fanOut('object.published', $event, [
            'objectId' => $event->aggregateId(),
        ]);
    }

    #[AsMessageHandler]
    public function onObjectArchived(ObjectArchived $event): void
    {
        $this->fanOut('object.archived', $event, [
            'objectId' => $event->aggregateId(),
        ]);
    }

    /**
     * AGENT-P8-04 (#1986) — agent lifecycle webhooks. The events live in
     * Shared\Contracts (not the Agent module), so this CORE subscriber
     * never references the Agent module (removability, ADR-0024);
     * with the module removed nothing dispatches them.
     */
    #[AsMessageHandler]
    public function onAgentRunAwaitingApproval(AgentRunAwaitingApproval $event): void
    {
        $this->fanOut(AgentRunAwaitingApproval::NAME, $event, [
            'runId' => $event->aggregateId(),
            'intent' => $event->intent,
            'affectedCount' => $event->affectedCount,
        ]);
    }

    #[AsMessageHandler]
    public function onAgentRunCompleted(AgentRunCompleted $event): void
    {
        $this->fanOut(AgentRunCompleted::NAME, $event, [
            'runId' => $event->aggregateId(),
            'intent' => $event->intent,
            'outcome' => $event->outcome,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fanOut(string $eventType, DomainEvent $event, array $data): void
    {
        // Bulk flows (import, bulk-edit/-delete) emit one event per object on the
        // SYNC bus; one webhook POST each would pile up + starve the 256 MiB
        // worker. A batch/summary delivery is the right shape (follow-up).
        if ($this->bulkContext->isBulk()) {
            return;
        }

        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            return;
        }

        $subscribers = $this->profiles->findWebhookSubscribersFor($eventType);
        if ([] === $subscribers) {
            return;
        }

        foreach ($subscribers as $profile) {
            $url = $profile->getWebhookUrl();
            if (null === $url || null === $profile->getWebhookSecret()) {
                continue;
            }

            $payload = [
                'event' => $eventType,
                'occurredOn' => $event->occurredOn()->format(DateTimeInterface::RFC3339_EXTENDED),
                'profileCode' => $profile->getCode(),
                'data' => $data,
            ];

            $delivery = new WebhookDelivery($profile->getId(), $eventType, $url, $payload);
            $this->deliveries->save($delivery);

            try {
                $this->bus->dispatch(new WebhookDeliveryMessage($delivery->getId(), $tenant->getId()));
            } catch (Throwable $exception) {
                // Best-effort toward the write path: a synchronous-transport
                // delivery failure must not bubble out. The audit row already
                // records the attempt; production retry runs off the worker.
                $this->logger->warning('Webhook delivery dispatch failed', [
                    'deliveryId' => $delivery->getId()->toRfc4122(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
