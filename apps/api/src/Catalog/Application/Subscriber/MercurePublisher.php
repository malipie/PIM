<?php

declare(strict_types=1);

namespace App\Catalog\Application\Subscriber;

use App\Catalog\Application\BulkContext;
use App\Catalog\Contracts\Event\ObjectArchived;
use App\Catalog\Contracts\Event\ObjectAttributesChanged;
use App\Catalog\Contracts\Event\ObjectCreated;
use App\Catalog\Contracts\Event\ObjectEnabledChanged;
use App\Catalog\Contracts\Event\ObjectPublished;
use App\Catalog\Contracts\Event\ObjectRejected;
use App\Catalog\Contracts\Event\ObjectRestored;
use App\Catalog\Contracts\Event\ObjectSubmittedForReview;
use App\Catalog\Contracts\Event\ObjectUnpublished;
use App\Catalog\Domain\ObjectKind;
use App\Shared\Application\TenantAwareMessage;
use App\Shared\Domain\DomainEvent;
use App\Shared\Infrastructure\Mercure\MercureSubscribeTopics;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;
use Throwable;

use const JSON_THROW_ON_ERROR;

/**
 * Publishes Catalog domain events to the Mercure hub for live admin
 * updates and (in phase 2) agent SSE streaming (#47 / 0.4.7).
 *
 * Each handler maps a domain event to:
 *   - **Type**: `object.<verb>.<kind>` (e.g. `object.created.product`)
 *     so subscribers can filter by both the operation and the kind.
 *   - **Topic**: `https://pim.localhost/tenant/<tid>/objects/<id>` (per
 *     row) plus `https://pim.localhost/tenant/<tid>/objects` (broadcast)
 *     so the admin can listen on a single topic for "any catalog change"
 *     or a row-specific topic for live editing. The `tenant/<tid>`
 *     prefix + `private: true` close the AUD-001 (#1573) cross-tenant
 *     leak — the hub only delivers a private update to a subscriber
 *     whose JWT authorises that exact tenant topic.
 *
 * The Mercure hub URL is the public origin from `MERCURE_PUBLIC_URL` —
 * subscribers connect there, the publisher pushes through
 * `MERCURE_URL` (internal). Topic strings are arbitrary IRIs
 * (Mercure's contract) and we keep them short + URI-shaped.
 *
 * Sync dispatch through `messenger.bus.default` keeps the publisher
 * simple — phase 2 may move to async if hub latency dominates the
 * write path. {@see DomainEventDispatcher} pulls the events out of
 * Doctrine's UoW post-flush, so the row is committed before the
 * Mercure push lands.
 */
final readonly class MercurePublisher
{
    private const string TOPIC_PREFIX_OBJECT = 'objects';

    private LoggerInterface $logger;

    public function __construct(
        private HubInterface $hub,
        private BulkContext $bulkContext,
        private string $topicBase = 'https://pim.localhost',
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    #[AsMessageHandler]
    public function onObjectCreated(ObjectCreated $event): void
    {
        $this->publish(
            type: 'object.created.'.$event->kind->value,
            event: $event,
            payload: [
                'objectId' => $event->aggregateId(),
                'kind' => $event->kind->value,
                'code' => $event->code,
            ],
        );
    }

    #[AsMessageHandler]
    public function onObjectAttributesChanged(ObjectAttributesChanged $event): void
    {
        $this->publish(
            type: 'object.attributes_changed',
            event: $event,
            payload: [
                'objectId' => $event->aggregateId(),
                'changedAttributeCodes' => $event->changedAttributeCodes,
            ],
        );
    }

    #[AsMessageHandler]
    public function onObjectEnabledChanged(ObjectEnabledChanged $event): void
    {
        $this->publish(
            type: 'object.enabled_changed',
            event: $event,
            payload: [
                'objectId' => $event->aggregateId(),
                'enabled' => $event->enabled,
            ],
        );
    }

    #[AsMessageHandler]
    public function onObjectPublished(ObjectPublished $event): void
    {
        $this->publish(
            type: 'object.published',
            event: $event,
            payload: ['objectId' => $event->aggregateId()],
        );
    }

    #[AsMessageHandler]
    public function onObjectArchived(ObjectArchived $event): void
    {
        $this->publish(
            type: 'object.archived',
            event: $event,
            payload: ['objectId' => $event->aggregateId()],
        );
    }

    #[AsMessageHandler]
    public function onObjectSubmittedForReview(ObjectSubmittedForReview $event): void
    {
        $this->publish(
            type: 'object.submitted_for_review',
            event: $event,
            payload: ['objectId' => $event->aggregateId(), 'comment' => $event->comment],
        );
    }

    #[AsMessageHandler]
    public function onObjectRejected(ObjectRejected $event): void
    {
        $this->publish(
            type: 'object.rejected',
            event: $event,
            payload: ['objectId' => $event->aggregateId(), 'comment' => $event->comment],
        );
    }

    #[AsMessageHandler]
    public function onObjectUnpublished(ObjectUnpublished $event): void
    {
        $this->publish(
            type: 'object.unpublished',
            event: $event,
            payload: ['objectId' => $event->aggregateId(), 'autoUnpublish' => $event->autoUnpublish],
        );
    }

    #[AsMessageHandler]
    public function onObjectRestored(ObjectRestored $event): void
    {
        $this->publish(
            type: 'object.restored',
            event: $event,
            payload: ['objectId' => $event->aggregateId()],
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function publish(string $type, DomainEvent&TenantAwareMessage $event, array $payload): void
    {
        // Bulk flows (import, bulk-edit/-delete) touch thousands of objects in
        // one process and route these events through the SYNC bus — one Mercure
        // HTTP push per object would pile up Symfony HttpClient CurlResponse
        // objects and OOM the 256 MiB worker. Per-row live updates are a
        // single-edit UX concern; a bulk run is not driving any open editor, so
        // skip the push (the deferred bulk rebuild reindexes the touched set
        // once). Mirrors {@see \App\Catalog\Infrastructure\Doctrine\EventListener\AttributesIndexedSyncListener}'s
        // BulkContext opt-out.
        if ($this->bulkContext->isBulk()) {
            return;
        }

        $tenantId = $event->tenantId();
        $rowTopic = MercureSubscribeTopics::objectRow($tenantId, $this->topicBase, $event->aggregateId());
        $broadcastTopic = MercureSubscribeTopics::objectsBroadcast($tenantId, $this->topicBase);

        $update = new Update(
            topics: [$rowTopic, $broadcastTopic],
            data: json_encode([
                'type' => $type,
                'occurredOn' => $event->occurredOn()->format(DateTimeInterface::RFC3339_EXTENDED),
                'data' => $payload,
            ], JSON_THROW_ON_ERROR),
            private: true,
        );

        // Hub failures (network, hub down, JWT mismatch) must not abort
        // the originating write — Mercure is a notification channel, not
        // the source of truth. Subscribers will reconnect on next push;
        // a logged warning is enough for operations to spot the gap.
        try {
            $this->hub->publish($update);
        } catch (Throwable $e) {
            $this->logger->warning('Mercure publish failed: {message}', [
                'message' => $e->getMessage(),
                'topics' => [$rowTopic, $broadcastTopic],
                'type' => $type,
                'aggregateId' => $event->aggregateId(),
            ]);
        }
    }

    /**
     * Helper used by ObjectKind switches outside this class. Kept here to
     * keep topic naming colocated with the publisher (one place to bump
     * the topic contract). Tenant-scoped (AUD-001 #1573) so a kind-filtered
     * subscription can never cross tenant boundaries.
     */
    public static function topicForKind(ObjectKind $kind, Uuid $tenantId, string $base = 'https://pim.localhost'): string
    {
        return MercureSubscribeTopics::tenantPrefix($tenantId, $base).'/'.self::TOPIC_PREFIX_OBJECT.'/kind/'.$kind->value;
    }
}
